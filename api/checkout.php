<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/demo-order.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => ['code' => 'method_not_allowed', 'message' => 'Use POST to save an order.']]);
    exit;
}

try {
    $hardware = medivendMachineConfig()['mode'] === 'hardware';
    if ($hardware) medivendRequireHardwareReady();
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        throw new MedivendCheckoutError(415, 'json_required', 'Send the order as JSON.');
    }
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) {
        throw new MedivendCheckoutError(400, 'invalid_idempotency_key', 'A valid checkout request key is required.');
    }
    $body = file_get_contents('php://input', false, null, 0, 65537);
    if ($body === false || strlen($body) > 65536) {
        throw new MedivendCheckoutError(413, 'request_too_large', 'The checkout request is too large.');
    }
    try {
        $payload = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new MedivendCheckoutError(400, 'invalid_json', 'The checkout request is not valid JSON.');
    }
    $items = medivendCheckoutItems($payload);
    require_once __DIR__ . '/../includes/database.php';
    $result = medivendSaveOrder(medivendDatabase(), $items, $key, !$hardware);
    $result += medivendDemoView($result['order'], $key);
    http_response_code($result['replayed'] ? 200 : 201);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (MedivendCheckoutError $error) {
    http_response_code($error->status);
    echo json_encode(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('[MediVend checkout] ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => [
        'code' => 'checkout_unavailable',
        'message' => 'The order could not be confirmed. Your cart is kept. Retry safely to check or save the same order.',
    ]]);
}
