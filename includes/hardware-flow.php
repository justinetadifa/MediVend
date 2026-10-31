<?php
declare(strict_types=1);
require_once __DIR__ . '/machine.php';
require_once __DIR__ . '/accepted-coin.php';
require_once __DIR__ . '/hardware-adapter.php';

function medivendHardwareReview(PDO $database, string $orderId): void
{
    $update = $database->prepare("UPDATE transactions SET order_status = 'needs_review', dispensing_status = 'needs_review' WHERE id = ?");
    $update->execute([$orderId]);
    $commands = $database->prepare("UPDATE hardware_commands SET status = 'needs_review' WHERE transaction_id = ? AND status IN ('queued','sent')");
    $commands->execute([$orderId]);
}

function medivendQueueHardwareUnit(PDO $database, string $reference): ?array
{
    $database->beginTransaction();
    try {
        $lock = $database->prepare('SELECT id FROM transactions WHERE reference = ? FOR UPDATE'); $lock->execute([$reference]);
        if (!$lock->fetch()) throw new RuntimeException('Unknown hardware order.');
        $order = medivendReadOrder($database, $reference);
        if ($order['is_demo'] || $order['payment_status'] !== 'paid' || $order['amount_paid_centavos'] !== $order['total_centavos']
            || !in_array($order['order_status'], ['pending','processing'], true)) { $database->commit(); return null; }
        // Recheck all remaining stock before the first command. Subsequent
        // units are checked here again and when sensor confirmation commits.
        $query = $database->prepare('SELECT stock_quantity, is_active, is_demo FROM products WHERE id = ? FOR UPDATE');
        foreach ($order['items'] as $item) {
            $query->execute([$item['product_id']]); $product = $query->fetch();
            if (!$product || (int) $product['is_demo'] !== 0 || !(int) $product['is_active']
                || (int) $product['stock_quantity'] < $item['quantity'] - $item['dispensed_quantity']) {
                medivendHardwareReview($database, $order['id']); $database->commit(); return null;
            }
        }
        foreach ($order['items'] as $item) {
            if ($item['dispensed_quantity'] >= $item['quantity']) continue;
            $unit = $item['dispensed_quantity'] + 1;
            $id = hash('sha256', 'medivend-unit-v1:' . $reference . ':' . $item['product_id'] . ':' . $unit);
            $exists = $database->prepare('SELECT id FROM hardware_commands WHERE id = ?'); $exists->execute([$id]);
            if (!$exists->fetch()) {
                $insert = $database->prepare('INSERT INTO hardware_commands (id, transaction_id, product_id, unit_number, compartment_number)
                    SELECT ?, transaction_id, product_id, ?, compartment_number FROM transaction_items WHERE transaction_id = ? AND product_id = ?');
                $insert->execute([$id, $unit, $order['id'], $item['product_id']]);
            }
            $update = $database->prepare("UPDATE transactions SET order_status = 'processing', dispensing_status = IF(dispensing_status = 'partial', 'partial', 'in_progress') WHERE id = ?");
            $update->execute([$order['id']]);
            $get = $database->prepare('SELECT * FROM hardware_commands WHERE id = ?'); $get->execute([$id]); $command = $get->fetch();
            $database->commit(); return $command;
        }
        $database->commit(); return null;
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}

function medivendClaimHardwareCommand(PDO $database, string $id, int $timeout): bool
{
    if ($timeout < 1 || $timeout > 86400) throw new RuntimeException('Verified delivery timeout required.');
    // Claim BEFORE I/O. A crash/uncertain dispatch is never automatically sent
    // again. Device-side persistent command deduplication is also mandatory.
    $owner = $database->prepare('SELECT transaction_id FROM hardware_commands WHERE id = ?'); $owner->execute([$id]); $orderId = $owner->fetchColumn();
    $database->beginTransaction();
    try {
        $lock = $database->prepare('SELECT order_status, payment_status, amount_paid_centavos, total_centavos FROM transactions WHERE id = ? FOR UPDATE'); $lock->execute([$orderId]); $order = $lock->fetch();
        $claimed = false;
        if ($order && $order['order_status'] === 'processing' && $order['payment_status'] === 'paid' && $order['amount_paid_centavos'] === $order['total_centavos']) {
            $claim = $database->prepare("UPDATE hardware_commands SET status = 'sent', deadline_at = TIMESTAMPADD(SECOND, ?, CURRENT_TIMESTAMP) WHERE id = ? AND status = 'queued'");
            $claim->execute([$timeout, $id]); $claimed = $claim->rowCount() === 1;
        }
        $database->commit(); return $claimed;
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}

function medivendHardwareDelivery(PDO $database, string $eventId, string $commandId, string $outcome): array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $eventId) || !preg_match('/\A[a-f0-9]{64}\z/', $commandId)
        || !in_array($outcome, ['sensor_confirmed','jam','timeout','uncertain'], true)) throw new RuntimeException('Invalid verified sensor event.');
    $owner = $database->prepare('SELECT transaction_id FROM hardware_commands WHERE id = ?'); $owner->execute([$commandId]); $orderId = $owner->fetchColumn();
    if (!$orderId) throw new RuntimeException('Unknown command; preserve sensor event for staff.');
    $database->beginTransaction();
    try {
        // Order first, then command/product: consistent with queue/review locks.
        $lock = $database->prepare('SELECT reference FROM transactions WHERE id = ? FOR UPDATE'); $lock->execute([$orderId]);
        $order = medivendReadOrder($database, $lock->fetchColumn());
        if ($order['is_demo']) throw new RuntimeException('Hardware cannot confirm a demo order.');
        $existing = $database->prepare('SELECT * FROM hardware_delivery_events WHERE event_id = ?'); $existing->execute([$eventId]); $event = $existing->fetch();
        if ($event) {
            if ($event['command_id'] !== $commandId || $event['outcome'] !== $outcome) throw new RuntimeException('Sensor event ID conflict.');
            $database->commit(); return $event + ['replayed' => true];
        }
        $get = $database->prepare('SELECT *, deadline_at < CURRENT_TIMESTAMP AS expired FROM hardware_commands WHERE id = ? FOR UPDATE');
        $get->execute([$commandId]); $command = $get->fetch();
        $result = 'needs_review';
        if ($command['status'] === 'confirmed' && $outcome === 'sensor_confirmed') $result = 'duplicate_command';
        elseif ($outcome === 'sensor_confirmed' && $command['status'] === 'sent' && $command['deadline_at'] !== null && !(bool) $command['expired']
            && $order['order_status'] === 'processing' && $order['payment_status'] === 'paid' && $order['amount_paid_centavos'] === $order['total_centavos']) {
            $item = $database->prepare('SELECT quantity, dispensed_quantity FROM transaction_items WHERE transaction_id = ? AND product_id = ? FOR UPDATE');
            $item->execute([$orderId, $command['product_id']]); $counts = $item->fetch();
            $product = $database->prepare('SELECT stock_quantity, is_active, is_demo FROM products WHERE id = ? FOR UPDATE');
            $product->execute([$command['product_id']]); $stock = $product->fetch();
            if ($counts && $counts['dispensed_quantity'] + 1 === (int) $command['unit_number'] && $counts['dispensed_quantity'] < $counts['quantity']
                && $stock && (int) $stock['is_demo'] === 0 && (int) $stock['is_active'] === 1 && (int) $stock['stock_quantity'] > 0) {
                $decrease = $database->prepare('UPDATE products SET stock_quantity = stock_quantity - 1 WHERE id = ? AND stock_quantity > 0'); $decrease->execute([$command['product_id']]);
                $increase = $database->prepare('UPDATE transaction_items SET dispensed_quantity = dispensed_quantity + 1 WHERE transaction_id = ? AND product_id = ? AND dispensed_quantity < quantity'); $increase->execute([$orderId, $command['product_id']]);
                if ($decrease->rowCount() !== 1 || $increase->rowCount() !== 1) throw new RuntimeException('Sensor confirmation failed atomically.');
                $done = $database->prepare("UPDATE hardware_commands SET status = 'confirmed' WHERE id = ?"); $done->execute([$commandId]);
                $remaining = $database->prepare('SELECT SUM(quantity - dispensed_quantity) FROM transaction_items WHERE transaction_id = ?'); $remaining->execute([$orderId]);
                $complete = (int) $remaining->fetchColumn() === 0;
                $update = $database->prepare('UPDATE transactions SET order_status = ?, dispensing_status = ?, completed_at = ' . ($complete ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE id = ?');
                $update->execute([$complete ? 'completed' : 'processing', $complete ? 'dispensed' : 'partial', $orderId]); $result = 'confirmed';
            }
        }
        if ($result === 'needs_review') medivendHardwareReview($database, (string) $orderId);
        $insert = $database->prepare('INSERT INTO hardware_delivery_events (event_id, command_id, outcome, result) VALUES (?, ?, ?, ?)'); $insert->execute([$eventId, $commandId, $outcome, $result]);
        $database->commit(); return ['event_id' => $eventId, 'command_id' => $commandId, 'outcome' => $outcome, 'result' => $result, 'replayed' => false];
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}

function medivendHardwareInterrupt(PDO $database): void
{
    $database->beginTransaction();
    try {
        $machine = $database->query('SELECT active_transaction_id FROM hardware_machine_state WHERE id = 1 FOR UPDATE')->fetch();
        if ($machine && $machine['active_transaction_id']) {
            $lock = $database->prepare('SELECT id, order_status, amount_paid_centavos FROM transactions WHERE id = ? FOR UPDATE'); $lock->execute([$machine['active_transaction_id']]); $order = $lock->fetch();
            if ($order && $order['order_status'] !== 'completed' && ((int) $order['amount_paid_centavos'] > 0 || $order['order_status'] === 'processing')) medivendHardwareReview($database, (string) $order['id']);
        }
        $database->commit();
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}
