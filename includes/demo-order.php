<?php
declare(strict_types=1);

require_once __DIR__ . '/checkout.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/coin-events.php';
require_once __DIR__ . '/machine.php';

function medivendDemoKey(): string
{
    $key = $_SERVER['HTTP_X_DEMO_ORDER_KEY'] ?? '';
    if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) {
        throw new MedivendCheckoutError(403, 'demo_order_required', 'Open the current saved demo order to use simulation controls.');
    }
    return $key;
}

function medivendDemoView(array $order, string $key): array
{
    // The token is a compare-and-swap event guard, tied to every persisted
    // balance/status/delivery counter. Once an event changes a row, its token
    // cannot be reused. Delivery uses this guard; payment has a persistent
    // unique-event ledger so the same coin can be retried safely.
    $remaining = max(0, $order['total_centavos'] - $order['amount_paid_centavos']);
    $accepting = $order['is_demo'] && $order['order_status'] === 'pending'
        && $order['dispensing_status'] === 'not_started' && in_array($order['payment_status'], ['pending', 'partial'], true);
    return ['order' => $order, 'state_token' => hash_hmac('sha256', json_encode($order, JSON_THROW_ON_ERROR), $key),
        'accepted_denominations_centavos' => MEDIVEND_COIN_DENOMINATIONS,
        'usable_denominations_centavos' => $accepting ? array_values(array_filter(MEDIVEND_COIN_DENOMINATIONS, static fn(int $coin): bool => $coin <= $remaining)) : [],
        'excess_centavos' => max(0, $order['amount_paid_centavos'] - $order['total_centavos']),
        'exact_payment_message' => MEDIVEND_EXACT_PAYMENT_MESSAGE];
}

function medivendDemoLock(PDO $database, string $key): array
{
    $reference = medivendCheckoutReference($key);
    $lock = $database->prepare('SELECT id FROM transactions WHERE reference = ? FOR UPDATE');
    $lock->execute([$reference]);
    if (!$lock->fetch()) throw new MedivendCheckoutError(404, 'order_not_found', 'The current order could not be found.');
    $order = medivendReadOrder($database, $reference);
    if (!$order || !$order['is_demo']) throw new MedivendCheckoutError(403, 'demo_only', 'Simulation is restricted to demo orders.');
    return $order;
}

function medivendDemoBody(array $fields): stdClass
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new MedivendCheckoutError(415, 'json_required', 'Send simulation events as JSON.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) throw new MedivendCheckoutError(413, 'request_too_large', 'The simulation event is too large.');
    try { $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new MedivendCheckoutError(400, 'invalid_json', 'Invalid simulation event JSON.'); }
    if (!$body instanceof stdClass) throw new MedivendCheckoutError(422, 'invalid_event', 'Invalid simulation event.');
    $keys = array_keys(get_object_vars($body)); sort($keys); sort($fields);
    if ($keys !== $fields) throw new MedivendCheckoutError(422, 'invalid_event', 'Send only the simulation event fields.');
    return $body;
}

function medivendDemoToken(array $order, string $key, $token): void
{
    if (!is_string($token) || !hash_equals(medivendDemoView($order, $key)['state_token'], $token)) {
        throw new MedivendCheckoutError(409, 'stale_event', 'This event was already handled or the order changed. Refresh status before another simulation.');
    }
}

function medivendDemoReview(PDO $database, array $order): void
{
    $update = $database->prepare("UPDATE transactions SET order_status = 'needs_review', dispensing_status = 'needs_review', completed_at = NULL WHERE id = ?");
    $update->execute([$order['id']]);
}

function medivendCloseUnpaidSession(PDO $database, array $order): void
{
    // The caller holds the same order-row lock used by coin events. Either
    // payment wins and closing is denied, or this unpaid session closes before
    // any later coin can be accepted. Never cancel or clear recorded credit.
    if ($order['amount_paid_centavos'] === 0 && $order['order_status'] === 'cancelled'
        && $order['payment_status'] === 'cancelled' && $order['dispensing_status'] === 'not_started') return;
    if ($order['amount_paid_centavos'] !== 0 || $order['payment_status'] !== 'pending'
        || $order['order_status'] !== 'pending' || $order['dispensing_status'] !== 'not_started') {
        throw new MedivendCheckoutError(409, 'session_protected', 'This order cannot be cleared. Its credit and delivery records are preserved. Please contact clinic staff. No refund has been issued.');
    }
    $close = $database->prepare("UPDATE transactions SET payment_status = 'cancelled', order_status = 'cancelled' WHERE id = ? AND amount_paid_centavos = 0");
    $close->execute([$order['id']]);
}

function medivendDemoDispense(PDO $database, array $order, string $action, ?int $productId): void
{
    if ($order['payment_status'] !== 'paid' || $order['amount_paid_centavos'] !== $order['total_centavos']) {
        throw new MedivendCheckoutError(409, 'payment_required', 'Demo payment must be complete before dispensing simulation.');
    }
    if ($action === 'start') {
        if ($order['order_status'] !== 'pending' || $order['dispensing_status'] !== 'not_started') throw new MedivendCheckoutError(409, 'dispensing_closed', 'Dispensing cannot be restarted. Please check the order status.');
        $productQuery = $database->prepare('SELECT stock_quantity, is_active, is_demo FROM products WHERE id = ? FOR UPDATE');
        foreach ($order['items'] as $item) {
            $productQuery->execute([$item['product_id']]);
            $product = $productQuery->fetch();
            if (!$product || !(int) $product['is_demo'] || !(int) $product['is_active']
                || (int) $product['stock_quantity'] < $item['quantity'] - $item['dispensed_quantity']) {
                medivendDemoReview($database, $order);
                return;
            }
        }
        $update = $database->prepare("UPDATE transactions SET dispensing_status = 'in_progress', order_status = 'processing' WHERE id = ?");
        $update->execute([$order['id']]);
        return;
    }
    if ($action === 'no_confirmation' && $order['order_status'] === 'pending' && $order['dispensing_status'] === 'not_started') {
        medivendDemoReview($database, $order);
        return;
    }
    if ($order['order_status'] !== 'processing' || !in_array($order['dispensing_status'], ['in_progress', 'partial'], true)) {
        throw new MedivendCheckoutError(409, 'dispensing_closed', 'This simulation has stopped. Please contact clinic staff if delivery is incomplete.');
    }
    if (in_array($action, ['jam', 'no_confirmation'], true)) {
        medivendDemoReview($database, $order);
        return;
    }
    if ($action !== 'delivered' || $productId === null) throw new MedivendCheckoutError(422, 'invalid_event', 'Choose a valid dispensing simulation outcome.');
    $selected = null;
    foreach ($order['items'] as $item) if ($item['product_id'] === $productId) $selected = $item;
    if (!$selected || $selected['dispensed_quantity'] >= $selected['quantity']) throw new MedivendCheckoutError(409, 'item_complete', 'This item has no remaining units to confirm.');
    $productQuery = $database->prepare('SELECT stock_quantity, is_active, is_demo FROM products WHERE id = ? FOR UPDATE');
    $productQuery->execute([$productId]);
    $product = $productQuery->fetch();
    if (!$product || !(int) $product['is_demo'] || !(int) $product['is_active'] || (int) $product['stock_quantity'] < 1) {
        medivendDemoReview($database, $order);
        return;
    }
    $stock = $database->prepare('UPDATE products SET stock_quantity = stock_quantity - 1 WHERE id = ? AND stock_quantity > 0 AND is_demo = 1');
    $stock->execute([$productId]);
    if ($stock->rowCount() !== 1) throw new RuntimeException('Stock confirmation failed.');
    $confirm = $database->prepare('UPDATE transaction_items SET dispensed_quantity = dispensed_quantity + 1 WHERE transaction_id = ? AND product_id = ? AND dispensed_quantity < quantity');
    $confirm->execute([$order['id'], $productId]);
    if ($confirm->rowCount() !== 1) throw new RuntimeException('Delivery confirmation failed.');
    $remaining = 0;
    foreach ($order['items'] as $item) $remaining += $item['quantity'] - $item['dispensed_quantity'];
    $complete = $remaining === 1;
    $update = $database->prepare('UPDATE transactions SET dispensing_status = ?, order_status = ?, completed_at = ' . ($complete ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE id = ?');
    $update->execute([$complete ? 'dispensed' : 'partial', $complete ? 'completed' : 'processing', $order['id']]);
}

function medivendDemoEndpoint(string $kind): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $database = null;
    $view = null;
    try {
        medivendRequireSimulation();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (($kind === 'status' && $method !== 'GET') || ($kind !== 'status' && $method !== 'POST')) {
            header('Allow: ' . ($kind === 'status' ? 'GET' : 'POST'));
            throw new MedivendCheckoutError(405, 'method_not_allowed', 'Unsupported simulation request method.');
        }
        $key = medivendDemoKey();
        $body = null;
        if ($kind === 'payment') {
            $body = medivendDemoBody(['event_id', 'coin_centavos']);
            if (!is_int($body->coin_centavos)) throw new MedivendCheckoutError(422, 'invalid_coin', 'Coin values must be integer centavos.');
            if (!is_string($body->event_id) || !preg_match('/\A[a-f0-9]{64}\z/', $body->event_id)) {
                throw new MedivendCheckoutError(422, 'invalid_event_id', 'A unique demo coin event ID is required.');
            }
        } elseif ($kind === 'dispense') {
            $body = medivendDemoBody(['state_token', 'action', 'product_id']);
            if (!is_string($body->action) || !in_array($body->action, ['start', 'delivered', 'jam', 'no_confirmation'], true)
                || ($body->action === 'delivered' ? !is_int($body->product_id) || $body->product_id < 1 : $body->product_id !== null)) {
                throw new MedivendCheckoutError(422, 'invalid_event', 'Choose a valid dispensing simulation outcome.');
            }
        } elseif ($kind === 'session') {
            $body = medivendDemoBody([]);
        }
        $database = medivendDatabase();
        $database->beginTransaction();
        $order = medivendDemoLock($database, $key);
        $view = medivendDemoView($order, $key);
        $coinEvent = null;
        if ($body !== null) {
            if ($kind === 'payment') $coinEvent = medivendProcessCoinEvent($database, $order, $body->event_id, $body->coin_centavos);
            elseif ($kind === 'session') medivendCloseUnpaidSession($database, $order);
            else {
                medivendDemoToken($order, $key, $body->state_token);
                medivendDemoDispense($database, $order, $body->action, $body->product_id);
            }
            $order = medivendReadOrder($database, $order['reference']);
        } elseif ($kind === 'status' && isset($_SERVER['HTTP_X_DEMO_EVENT_ID'])) {
            $eventId = $_SERVER['HTTP_X_DEMO_EVENT_ID'];
            if (!preg_match('/\A[a-f0-9]{64}\z/', $eventId)) throw new MedivendCheckoutError(422, 'invalid_event_id', 'Invalid demo event lookup.');
            $coinEvent = medivendReadCoinEvent($database, $eventId);
            if ($coinEvent && $coinEvent['transaction_id'] !== $order['id']) throw new MedivendCheckoutError(409, 'coin_event_conflict', 'That event belongs to another order. Please contact clinic staff.');
            if ($coinEvent) $coinEvent['replayed'] = true;
        }
        $database->commit();
        $response = medivendDemoView($order, $key);
        if ($kind === 'payment' || isset($_SERVER['HTTP_X_DEMO_EVENT_ID'])) $response['coin_event'] = $coinEvent;
        if ($kind === 'payment' && $coinEvent['outcome'] === 'rejected') {
            http_response_code(409);
            $response['error'] = ['code' => $coinEvent['reason_code'], 'message' => $coinEvent['message']];
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        if ($database instanceof PDO && $database->inTransaction()) $database->rollBack();
        $known = $error instanceof MedivendCheckoutError;
        http_response_code($known ? $error->status : 503);
        if (!$known) error_log('[MediVend demo] ' . $error->getMessage());
        $response = ['error' => ['code' => $known ? $error->errorCode : 'simulation_unavailable',
            'message' => $known ? $error->getMessage() : 'The simulation result could not be confirmed. Refresh status; do not resend delivery events.']];
        if ($known && $view) $response += $view;
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
