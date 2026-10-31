<?php
declare(strict_types=1);

function medivendReadCoinEvent(PDO $database, string $eventId): ?array
{
    $statement = $database->prepare('SELECT event_id, transaction_id, coin_centavos, outcome, reason_code, message,
        amount_before_centavos, amount_after_centavos, payment_status_after FROM demo_coin_events WHERE event_id = ?');
    $statement->execute([$eventId]);
    $row = $statement->fetch();
    if (!$row) return null;
    return [
        'event_id' => $row['event_id'], 'transaction_id' => (string) $row['transaction_id'],
        'coin_centavos' => (int) $row['coin_centavos'], 'outcome' => $row['outcome'],
        'reason_code' => $row['reason_code'], 'message' => $row['message'],
        'amount_before_centavos' => (int) $row['amount_before_centavos'],
        'amount_after_centavos' => (int) $row['amount_after_centavos'],
        'payment_status_after' => $row['payment_status_after'],
    ];
}

function medivendSmallerCoinMessage(int $remaining): string
{
    $coins = [];
    foreach ([100, 500, 1000] as $coin) if ($coin <= $remaining) $coins[] = '₱' . intdiv($coin, 100);
    return $coins ? 'Use simulated ' . implode(' or ', $coins) . ' coins. Exact payment only. No change.'
        : 'No supported demo coin fits the balance. Please contact clinic staff. Exact payment only. No change.';
}

function medivendProcessCoinEvent(PDO $database, array $order, string $eventId, int $coin): array
{
    // Caller holds the order-row lock and database transaction. Same-order
    // requests serialize, while the global unique ID also blocks cross-order
    // reuse. Ledger insertion and balance update commit or roll back together.
    $existing = medivendReadCoinEvent($database, $eventId);
    if ($existing) {
        if ($existing['transaction_id'] !== $order['id'] || $existing['coin_centavos'] !== $coin) {
            throw new MedivendCheckoutError(409, 'coin_event_conflict', 'This demo coin event ID was already used for a different event. Please contact clinic staff.');
        }
        return $existing + ['replayed' => true];
    }
    if (!in_array($coin, [100, 500, 1000], true)) {
        throw new MedivendCheckoutError(422, 'invalid_coin', 'Only integer-centavo demo ₱1, ₱5, and ₱10 events are supported.');
    }
    $before = $order['amount_paid_centavos'];
    $after = $before;
    $paymentStatus = $order['payment_status'];
    $reason = null;
    $message = 'Demo coin accepted.';
    if ($order['order_status'] !== 'pending' || $order['dispensing_status'] !== 'not_started'
        || !in_array($order['payment_status'], ['pending', 'partial'], true) || $before >= $order['total_centavos']) {
        $reason = 'payment_closed';
        $message = 'This order is not accepting further demo coins. Existing credit is preserved. No refund has been issued.';
    } elseif ($coin > $order['total_centavos'] - $before) {
        $reason = 'no_change';
        $message = 'This simulated coin exceeds the remaining balance. ' . medivendSmallerCoinMessage($order['total_centavos'] - $before);
    } else {
        $after += $coin;
        $paymentStatus = $after === $order['total_centavos'] ? 'paid' : 'partial';
    }
    $insert = $database->prepare('INSERT INTO demo_coin_events
        (event_id, transaction_id, coin_centavos, outcome, reason_code, message, amount_before_centavos, amount_after_centavos, payment_status_after)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    try {
        $insert->execute([$eventId, $order['id'], $coin, $reason ? 'rejected' : 'accepted', $reason, $message, $before, $after, $paymentStatus]);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new MedivendCheckoutError(409, 'coin_event_conflict', 'This demo coin event ID was already used. Refresh status or contact clinic staff.');
        }
        throw $error;
    }
    if ($reason === null) {
        $update = $database->prepare('UPDATE transactions SET amount_paid_centavos = ?, payment_status = ? WHERE id = ?');
        $update->execute([$after, $paymentStatus, $order['id']]);
    }
    return medivendReadCoinEvent($database, $eventId) + ['replayed' => false];
}
