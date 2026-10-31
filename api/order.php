<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/demo-order.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$database = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); throw new MedivendCheckoutError(405, 'method_not_allowed', 'Use GET for saved status.'); }
    $key = $_SERVER['HTTP_X_ORDER_KEY'] ?? '';
    if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) throw new MedivendCheckoutError(403, 'order_required', 'Open the saved order or contact clinic staff.');
    $database = medivendDatabase(); $database->beginTransaction();
    $order = medivendReadOrder($database, medivendCheckoutReference($key));
    if (!$order) throw new MedivendCheckoutError(404, 'order_not_found', 'Please contact clinic staff. Saved status could not be found.');
    $response = medivendDemoView($order, $key); $response['exact_payment_message'] = 'Exact payment only. No change.';
    $database->commit();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if ($database && $database->inTransaction()) $database->rollBack();
    http_response_code($error instanceof MedivendCheckoutError ? $error->status : 503);
    echo json_encode(['error' => ['message' => $error instanceof MedivendCheckoutError ? $error->getMessage() : 'Machine unavailable. Recorded credit is preserved. Please contact clinic staff.']]);
}
