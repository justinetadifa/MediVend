<?php
declare(strict_types=1);

require_once __DIR__ . '/checkout.php';
require_once __DIR__ . '/coin-events.php';

/**
 * Trusted-server recorder for a coin that has ALREADY been accepted.
 * Not a browser endpoint or a hardware connection. Tests simulate this input.
 * A future verified adapter must durably retain its stable event ID until this
 * transaction commits; errors must be surfaced/retried, never treated as return
 * of money. Never route an observed accepted coin through the demo reject path.
 */
function medivendRecordAcceptedCoin(PDO $database, string $reference, string $eventId, int $coin): array
{
    if (!preg_match('/\AMV-[A-F0-9]{32}\z/', $reference) || !preg_match('/\A[a-f0-9]{64}\z/', $eventId)
        || !in_array($coin, MEDIVEND_COIN_DENOMINATIONS, true)) {
        throw new MedivendCheckoutError(422, 'invalid_accepted_coin', 'A valid order, unique accepted-coin event ID and integer denomination are required.');
    }
    $database->beginTransaction();
    try {
        $lock = $database->prepare('SELECT id FROM transactions WHERE reference = ? FOR UPDATE');
        $lock->execute([$reference]);
        if (!$lock->fetch()) throw new MedivendCheckoutError(404, 'order_not_found', 'Accepted coin could not be assigned. Preserve the event and contact clinic staff.');
        $order = medivendReadOrder($database, $reference);
        $existing = medivendReadCoinEvent($database, $eventId);
        if ($existing) {
            if ($existing['transaction_id'] !== $order['id'] || $existing['coin_centavos'] !== $coin || $existing['event_source'] !== 'accepted_coin') {
                throw new MedivendCheckoutError(409, 'coin_event_conflict', 'Accepted-coin event ID conflict. Preserve the event and contact clinic staff.');
            }
            $database->commit();
            return ['order' => $order, 'coin_event' => $existing + ['replayed' => true]];
        }
        $before = $order['amount_paid_centavos'];
        // Never cap/truncate money to the order total or report a failed write as
        // success. A future adapter must keep this event queued on this error.
        if ($before > min(4294967295, PHP_INT_MAX) - $coin) throw new RuntimeException('Credit storage limit reached. Preserve accepted-coin event for staff; no credit was discarded or returned.');
        $after = $before + $coin;
        $review = $after > $order['total_centavos'] || $order['order_status'] !== 'pending'
            || $order['dispensing_status'] !== 'not_started' || !in_array($order['payment_status'], ['pending', 'partial'], true);
        $status = $after >= $order['total_centavos'] ? 'paid' : 'partial';
        $message = $review
            ? 'Accepted coin recorded in full. Please contact clinic staff. Automatic dispensing is stopped. No change or refund has been returned.'
            : 'Accepted coin recorded in full.';
        $insert = $database->prepare("INSERT INTO demo_coin_events
            (event_id, transaction_id, coin_centavos, event_source, outcome, reason_code, message, amount_before_centavos, amount_after_centavos, payment_status_after)
            VALUES (?, ?, ?, 'accepted_coin', 'accepted', ?, ?, ?, ?, ?)");
        $insert->execute([$eventId, $order['id'], $coin, $review ? 'accepted_coin_review' : null, $message, $before, $after, $status]);
        $update = $database->prepare('UPDATE transactions SET amount_paid_centavos = ?, payment_status = ?, order_status = ?, dispensing_status = ? WHERE id = ?');
        $update->execute([$after, $status, $review ? 'needs_review' : $order['order_status'],
            $review ? 'needs_review' : $order['dispensing_status'], $order['id']]);
        $result = ['order' => medivendReadOrder($database, $reference), 'coin_event' => medivendReadCoinEvent($database, $eventId) + ['replayed' => false]];
        $database->commit();
        return $result;
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollBack();
        throw $error;
    }
}
