<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/demo-order.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$database = null;
try {
    if (medivendMachineConfig()['mode'] !== 'hardware') throw new MedivendCheckoutError(403, 'hardware_only', 'Use the current session.');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Allow: POST'); throw new MedivendCheckoutError(405, 'method_not_allowed', 'Use POST to close the session.'); }
    medivendDemoBody([]);
    $key = $_SERVER['HTTP_X_ORDER_KEY'] ?? '';
    if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) throw new MedivendCheckoutError(403, 'order_required', 'Saved order required.');
    $database = medivendDatabase(); $database->beginTransaction();
    $database->query('SELECT id FROM hardware_machine_state WHERE id = 1 FOR UPDATE')->fetch();
    $lock = $database->prepare('SELECT id FROM transactions WHERE reference = ? FOR UPDATE'); $lock->execute([medivendCheckoutReference($key)]);
    if (!$lock->fetch()) throw new MedivendCheckoutError(404, 'order_not_found', 'Please contact clinic staff.');
    $order = medivendReadOrder($database, medivendCheckoutReference($key));
    if ($order['is_demo']) throw new MedivendCheckoutError(409, 'session_protected', 'Please contact clinic staff. The saved session is preserved.');
    if ($order['order_status'] === 'completed') {
        $confirmed = $order['payment_status'] === 'paid' && $order['amount_paid_centavos'] === $order['total_centavos'] && $order['dispensing_status'] === 'dispensed';
        foreach ($order['items'] as $item) if ($item['dispensed_quantity'] !== $item['quantity']) $confirmed = false;
        if (!$confirmed) throw new MedivendCheckoutError(409, 'session_protected', 'Please contact clinic staff. Delivery is unresolved.');
    } else medivendCloseUnpaidSession($database, $order);
    $release = $database->prepare('UPDATE hardware_machine_state SET active_transaction_id = NULL WHERE id = 1 AND active_transaction_id = ?'); $release->execute([$order['id']]);
    $database->commit(); echo json_encode(['closed' => true]);
} catch (Throwable $error) {
    if ($database && $database->inTransaction()) $database->rollBack();
    http_response_code($error instanceof MedivendCheckoutError ? $error->status : 503);
    echo json_encode(['error' => ['message' => 'Please contact clinic staff. Your saved order and credit are preserved. No refund has been issued.']]);
}
