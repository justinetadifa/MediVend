<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/checkout.php';

function expectCheckout(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function checkoutCurl(string $url, $payload, ?string $key, string $method = 'POST', string $contentType = 'application/json')
{
    $handle = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: ' . $contentType];
    if ($key !== null) $headers[] = 'Idempotency-Key: ' . $key;
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
    if ($method === 'POST') curl_setopt($handle, CURLOPT_POSTFIELDS, is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR));
    return $handle;
}

function checkoutRequest(string $url, $payload, ?string $key, string $method = 'POST', string $contentType = 'application/json'): array
{
    $handle = checkoutCurl($url, $payload, $key, $method, $contentType);
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    expectCheckout(is_string($body), 'HTTP request failed: ' . $error);
    return ['status' => $status, 'data' => json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
}

function parallelCheckouts(string $url, array $payloads, string $key): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($payloads as $payload) {
        $handle = checkoutCurl($url, $payload, $key);
        $handles[] = $handle;
        curl_multi_add_handle($multi, $handle);
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, .2);
    } while ($running && $status === CURLM_OK);
    $results = [];
    foreach ($handles as $handle) {
        $results[] = ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'data' => json_decode(curl_multi_getcontent($handle), true, 512, JSON_THROW_ON_ERROR)];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    return $results;
}

$database = null;
$ownedReferences = [];
$fixtureIds = [];
$failed = false;
try {
    expectCheckout(extension_loaded('curl'), 'Enable the XAMPP curl extension for HTTP regression tests.');
    $database = medivendDatabase();
    $originalProducts = $database->query('SELECT * FROM products ORDER BY id')->fetchAll();
    $initialOrders = (int) $database->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    $initialItems = (int) $database->query('SELECT COUNT(*) FROM transaction_items')->fetchColumn();
    $baseUrl = rtrim(getenv('MEDIVEND_TEST_BASE_URL') ?: 'http://localhost/MediVend', '/');
    $url = $baseUrl . '/api/checkout.php';
    $usedCompartments = array_fill_keys(array_column($originalProducts, 'compartment_number'), true);
    $compartments = [];
    for ($slot = 1; $slot <= 65535 && count($compartments) < 3; $slot++) {
        if (!isset($usedCompartments[$slot])) $compartments[] = $slot;
    }
    expectCheckout(count($compartments) === 3, 'Three unused compartments are needed for temporary test products.');
    $tag = 'TEST-CHECKOUT-' . bin2hex(random_bytes(5));
    $insert = $database->prepare('INSERT INTO products (sku, name, description, price_centavos, stock_quantity, compartment_number, is_active, is_demo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    foreach ([[1234, 7, 1], [500, 3, 1], [100, 2, 0]] as $index => $values) {
        $insert->execute([$tag . '-' . $index, 'Checkout fixture ' . $index, 'Temporary automated checkout test', $values[0], $values[1], $compartments[$index], $values[2]]);
        $fixtureIds[] = (int) $database->lastInsertId();
    }
    [$a, $b, $inactive] = $fixtureIds;
    $newKey = static function () use ($database, &$ownedReferences): string {
        $key = bin2hex(random_bytes(32));
        $reference = medivendCheckoutReference($key);
        $query = $database->prepare('SELECT COUNT(*) FROM transactions WHERE reference = ?');
        $query->execute([$reference]);
        expectCheckout((int) $query->fetchColumn() === 0, 'A generated test reference already exists.');
        $ownedReferences[] = $reference;
        return $key;
    };
    $counts = static fn(): array => [(int) $database->query('SELECT COUNT(*) FROM transactions')->fetchColumn(), (int) $database->query('SELECT COUNT(*) FROM transaction_items')->fetchColumn()];
    $payload = ['items' => [['product_id' => $a, 'quantity' => 2]]];
    $key = $newKey();
    $first = checkoutRequest($url, $payload, $key);
    expectCheckout($first['status'] === 201, 'A valid cart must create one order: ' . json_encode($first));
    $order = $first['data']['order'];
    expectCheckout($order['total_centavos'] === 2468, 'The server must price the order from MySQL.');
    expectCheckout(preg_match('/\AMV-[A-F0-9]{32}\z/', $order['reference']) === 1, 'A unique saved reference is required.');
    expectCheckout($order['payment_status'] === 'pending' && $order['dispensing_status'] === 'not_started' && $order['order_status'] === 'pending', 'New order statuses must remain pending/not_started.');
    expectCheckout($order['amount_paid_centavos'] === 0 && $order['is_demo'] === true, 'Real payments must be unavailable.');
    expectCheckout($order['items'][0]['unit_price_centavos'] === 1234 && $order['items'][0]['dispensed_quantity'] === 0, 'Saved snapshots must contain database prices and zero dispensing.');
    $snapshot = $database->prepare('SELECT ti.*, t.completed_at FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE t.reference = ?');
    $snapshot->execute([$order['reference']]);
    $line = $snapshot->fetch();
    expectCheckout($line['product_name'] === 'Checkout fixture 0' && (int) $line['compartment_number'] === $compartments[0] && (int) $line['quantity'] === 2 && $line['completed_at'] === null, 'Names, compartments, and quantities must be snapshotted without marking completion.');

    $repeat = checkoutRequest($url, $payload, $key);
    expectCheckout($repeat['status'] === 200 && $repeat['data']['replayed'] === true && $repeat['data']['order'] === $order, 'A retry must return the exact saved order.');
    $conflict = checkoutRequest($url, ['items' => [['product_id' => $a, 'quantity' => 3]]], $key);
    expectCheckout($conflict['status'] === 409 && $conflict['data']['error']['code'] === 'idempotency_conflict', 'Reusing a key for another cart must be rejected.');

    $change = $database->prepare('UPDATE products SET name = ?, price_centavos = ?, stock_quantity = ?, is_active = ? WHERE id = ?');
    $change->execute(['Renamed fixture', 2000, 0, 0, $a]);
    $replayAfterChange = checkoutRequest($url, $payload, $key);
    expectCheckout($replayAfterChange['status'] === 200 && $replayAfterChange['data']['order'] === $order, 'Recovery must return original snapshots even when current products changed.');
    $change->execute(['Checkout fixture 0', 1234, 7, 1, $a]);

    foreach ([0, -1, 1.5, '2', true, null, 65536] as $quantity) {
        $result = checkoutRequest($url, ['items' => [['product_id' => $a, 'quantity' => $quantity]]], $newKey());
        expectCheckout($result['status'] === 422, 'Invalid quantities must be rejected.');
    }
    foreach ([['items' => []], ['items' => [['product_id' => 0, 'quantity' => 1]]],
        ['items' => [['product_id' => $a, 'quantity' => 1], ['product_id' => $a, 'quantity' => 1]]],
        ['items' => [['product_id' => $a, 'quantity' => 1, 'unit_price_centavos' => 1]]],
        ['items' => [['product_id' => $a, 'quantity' => 1]], 'total_centavos' => 1],
        ['items' => [['product_id' => $a, 'quantity' => 1]], 'payment_status' => 'paid']] as $badPayload) {
        expectCheckout(checkoutRequest($url, $badPayload, $newKey())['status'] === 422, 'Only product IDs and quantities may be supplied.');
    }
    expectCheckout(checkoutRequest($url, '{', $newKey())['status'] === 400, 'Malformed JSON must be rejected.');
    expectCheckout(checkoutRequest($url, $payload, null)['status'] === 400, 'A missing retry key must be rejected.');
    expectCheckout(checkoutRequest($url, $payload, $newKey(), 'POST', 'text/plain')['status'] === 415, 'Checkout must require JSON.');
    expectCheckout(checkoutRequest($url, null, null, 'GET')['status'] === 405, 'Checkout is POST only.');

    // The first item is inserted before validation fails on a later item.
    // Neither that item nor the provisional transaction may survive rollback.
    foreach ([[$b, 4, 'insufficient_stock'], [$inactive, 1, 'product_unavailable'], [4294967295, 1, 'product_unavailable']] as [$id, $quantity, $code]) {
        $before = $counts();
        $result = checkoutRequest($url, ['items' => [['product_id' => $a, 'quantity' => 1], ['product_id' => $id, 'quantity' => $quantity]]], $newKey());
        expectCheckout($result['status'] === 409 && $result['data']['error']['code'] === $code, 'Unavailable or insufficient stock must fail clearly.');
        expectCheckout($counts() === $before, 'Failure after inserting an earlier item must roll back every order/item write.');
    }
    $maxPrice = $database->prepare('UPDATE products SET price_centavos = ? WHERE id = ?');
    $maxPrice->execute([4294967295, $b]);
    $before = $counts();
    $overflow = checkoutRequest($url, ['items' => [['product_id' => $a, 'quantity' => 1], ['product_id' => $b, 'quantity' => 1]]], $newKey());
    expectCheckout($overflow['status'] === 422 && $counts() === $before, 'Totals beyond the schema limit must roll back.');
    $maxPrice->execute([500, $b]);

    $multi = ['items' => [['product_id' => $b, 'quantity' => 2], ['product_id' => $a, 'quantity' => 1]]];
    $before = $counts();
    $parallel = parallelCheckouts($url, array_fill(0, 8, $multi), $newKey());
    $references = [];
    $created = 0;
    foreach ($parallel as $result) {
        expectCheckout(in_array($result['status'], [200, 201], true), 'Concurrent retries must succeed or replay: ' . json_encode($result));
        $created += (int) ($result['status'] === 201);
        $references[] = $result['data']['order']['reference'];
        expectCheckout($result['data']['order']['total_centavos'] === 2234, 'Concurrent orders must use database totals.');
    }
    expectCheckout($created === 1 && count(array_unique($references)) === 1 && $counts() === [$before[0] + 1, $before[1] + 2], 'Eight concurrent retries must create exactly one order and one set of items.');

    $before = $counts();
    $mixed = parallelCheckouts($url, [['items' => [['product_id' => $a, 'quantity' => 1]]], ['items' => [['product_id' => $b, 'quantity' => 1]]]], $newKey());
    $statuses = array_column($mixed, 'status');
    sort($statuses);
    expectCheckout($statuses === [201, 409] && $counts() === [$before[0] + 1, $before[1] + 1], 'Concurrent different carts cannot share a request key.');

    $stocks = $database->prepare('SELECT stock_quantity FROM products WHERE id IN (?, ?, ?) ORDER BY id');
    $stocks->execute($fixtureIds);
    expectCheckout(array_map('intval', $stocks->fetchAll(PDO::FETCH_COLUMN)) === [7, 3, 2], 'Saving orders must not decrement or reserve stock.');
    echo 'PASS: authoritative prices, pending statuses, snapshots, validation, atomic rollback, durable retry replay, and concurrent duplicate protection.' . PHP_EOL;
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
} finally {
    if ($database instanceof PDO) {
        if ($database->inTransaction()) $database->rollBack();
        $database->beginTransaction();
        $deleteItems = $database->prepare('DELETE ti FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE t.reference = ?');
        $deleteOrder = $database->prepare('DELETE FROM transactions WHERE reference = ?');
        foreach ($ownedReferences as $reference) {
            $deleteItems->execute([$reference]);
            $deleteOrder->execute([$reference]);
        }
        $deleteProduct = $database->prepare('DELETE FROM products WHERE id = ? AND sku LIKE ?');
        foreach ($fixtureIds as $id) $deleteProduct->execute([$id, $tag . '-%']);
        $database->commit();
        if (isset($originalProducts)) {
            if ($database->query('SELECT * FROM products ORDER BY id')->fetchAll() !== $originalProducts
                || (int) $database->query('SELECT COUNT(*) FROM transactions')->fetchColumn() !== $initialOrders
                || (int) $database->query('SELECT COUNT(*) FROM transaction_items')->fetchColumn() !== $initialItems) {
                $failed = true;
                fwrite(STDERR, 'FAIL: Test cleanup did not restore the original rows.' . PHP_EOL);
            } else echo 'Temporary test products/orders removed; original rows are unchanged.' . PHP_EOL;
        }
    }
}
exit($failed ? 1 : 0);
