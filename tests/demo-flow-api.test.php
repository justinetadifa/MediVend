<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/demo-order.php';

function demoAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function demoHttp(string $path, string $key, ?array $body = null): array
{
    $url = rtrim(getenv('MEDIVEND_TEST_BASE_URL') ?: 'http://localhost/MediVend', '/') . '/api/' . $path . '.php';
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Demo-Order-Key: ' . $key]]);
    if ($body !== null) { curl_setopt($handle, CURLOPT_POST, true); curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body)); }
    $text = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
    demoAssert(is_string($text), 'Could not reach XAMPP simulation endpoint.');
    return ['status' => $status, 'body' => json_decode($text, true, 512, JSON_THROW_ON_ERROR)];
}
function demoCoin(string $key, array $state, int $coin): array
{
    return demoHttp('demo-payment', $key, ['state_token' => $state['state_token'], 'coin_centavos' => $coin]);
}
function demoDelivery(string $key, array $state, string $action, ?int $productId = null): array
{
    return demoHttp('demo-dispense', $key, ['state_token' => $state['state_token'], 'action' => $action, 'product_id' => $productId]);
}

function demoParallel(string $path, string $key, array $body): array
{
    $url = rtrim(getenv('MEDIVEND_TEST_BASE_URL') ?: 'http://localhost/MediVend', '/') . '/api/' . $path . '.php';
    $multi = curl_multi_init(); $handles = [];
    for ($index = 0; $index < 6; $index++) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Demo-Order-Key: ' . $key]]);
        $handles[] = $handle; curl_multi_add_handle($multi, $handle);
    }
    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, .2); } while ($running);
    $statuses = [];
    foreach ($handles as $handle) { $statuses[] = curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_multi_remove_handle($multi, $handle); curl_close($handle); }
    curl_multi_close($multi); sort($statuses); return $statuses;
}

$database = medivendDatabase();
$original = $database->query('SELECT * FROM products ORDER BY id')->fetchAll();
$ordersBefore = (int) $database->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
$itemsBefore = (int) $database->query('SELECT COUNT(*) FROM transaction_items')->fetchColumn();
$owned = []; $fixtureIds = []; $failed = false;
$tag = 'TEST-SIM-' . bin2hex(random_bytes(5));
try {
    $used = array_fill_keys(array_column($original, 'compartment_number'), true);
    $slots = [];
    for ($slot = 1; $slot <= 65535 && count($slots) < 3; $slot++) if (!isset($used[$slot])) $slots[] = $slot;
    demoAssert(count($slots) === 3, 'Three temporary fixture slots are needed.');
    $insert = $database->prepare('INSERT INTO products (sku, name, price_centavos, stock_quantity, compartment_number, is_active, is_demo) VALUES (?, ?, ?, ?, ?, 1, ?)');
    foreach ([[500, 20, 1], [100, 20, 1], [500, 3, 0]] as $index => $data) {
        $insert->execute([$tag . '-' . $index, 'Simulation test ' . $index, $data[0], $data[1], $slots[$index], $data[2]]);
        $fixtureIds[] = (int) $database->lastInsertId();
    }
    [$a, $b, $real] = $fixtureIds;
    $make = static function (array $items) use ($database, &$owned): array {
        $key = bin2hex(random_bytes(32));
        $result = medivendSaveOrder($database, medivendCheckoutItems(json_decode(json_encode(['items' => $items]))), $key);
        $owned[] = $result['order']['reference'];
        return [$key, medivendDemoView($result['order'], $key)];
    };
    $stockOf = static function (int $id) use ($database): int { $query = $database->prepare('SELECT stock_quantity FROM products WHERE id = ?'); $query->execute([$id]); return (int) $query->fetchColumn(); };

    [$key, $state] = $make([['product_id' => $a, 'quantity' => 2], ['product_id' => $b, 'quantity' => 1]]);
    demoAssert(demoHttp('demo-order', '')['status'] === 403, 'Unknown current-order capability must be rejected.');
    demoAssert(demoHttp('demo-order', bin2hex(random_bytes(32)))['status'] === 404, 'A different order key cannot access this order.');
    demoAssert(demoDelivery($key, $state, 'start')['status'] === 409, 'Unpaid orders cannot start delivery.');
    demoAssert(demoCoin($key, $state, 200)['status'] === 422, 'Unsupported coins must be rejected.');
    $paid1 = demoCoin($key, $state, 100);
    demoAssert($paid1['status'] === 200 && $paid1['body']['order']['amount_paid_centavos'] === 100 && $paid1['body']['order']['payment_status'] === 'partial', 'One peso must be partial.');
    demoAssert(demoCoin($key, $state, 100)['status'] === 409, 'Duplicate coin token must not count twice.');
    $paid = demoCoin($key, $paid1['body'], 1000);
    demoAssert($paid['body']['order']['amount_paid_centavos'] === 1100 && $paid['body']['order']['payment_status'] === 'paid', 'Exact integer total becomes paid.');
    demoAssert($paid['body']['order']['dispensing_status'] === 'not_started' && $stockOf($a) === 20, 'Coins never dispense or alter stock.');
    demoAssert(demoCoin($key, $paid['body'], 100)['status'] === 409, 'Already paid order rejects more coins.');

    $started = demoDelivery($key, $paid['body'], 'start');
    demoAssert($started['body']['order']['dispensing_status'] === 'in_progress', 'Starting enters processing.');
    demoAssert(demoDelivery($key, $paid['body'], 'start')['status'] === 409, 'A duplicated start is rejected.');
    $partial = demoDelivery($key, $started['body'], 'delivered', $a);
    demoAssert($partial['body']['order']['items'][0]['dispensed_quantity'] === 1 && $stockOf($a) === 19, 'Confirmed unit decrements stock atomically.');
    demoAssert($partial['body']['order']['order_status'] === 'processing', 'Partial delivery is not complete.');
    demoAssert(demoDelivery($key, $started['body'], 'delivered', $a)['status'] === 409 && $stockOf($a) === 19, 'Duplicate delivery changes neither stock nor count.');
    $jam = demoDelivery($key, $partial['body'], 'jam');
    demoAssert($jam['body']['order']['order_status'] === 'needs_review' && $jam['body']['order']['dispensing_status'] === 'needs_review', 'Jam stops for staff review.');
    demoAssert($jam['body']['order']['items'][0]['dispensed_quantity'] === 1 && $jam['body']['order']['payment_status'] === 'paid', 'Jam preserves delivery and does not claim refund.');
    demoAssert(demoDelivery($key, $jam['body'], 'delivered', $a)['status'] === 409, 'Needs-review orders cannot resume.');

    [$completeKey, $completeState] = $make([['product_id' => $a, 'quantity' => 2]]);
    $p = demoCoin($completeKey, $completeState, 1000)['body'];
    $s = demoDelivery($completeKey, $p, 'start')['body'];
    $one = demoDelivery($completeKey, $s, 'delivered', $a)['body'];
    $done = demoDelivery($completeKey, $one, 'delivered', $a)['body'];
    demoAssert($done['order']['order_status'] === 'completed' && $done['order']['dispensing_status'] === 'dispensed' && $done['order']['completed_at'] !== null, 'Only all-confirmed orders complete.');
    demoAssert($done['order']['items'][0]['dispensed_quantity'] === 2 && $stockOf($a) === 17, 'Exactly two stock units consumed.');
    demoAssert(demoDelivery($completeKey, $done, 'delivered', $a)['status'] === 409 && $stockOf($a) === 17, 'Cannot dispense beyond ordered quantity.');

    [$uncertainKey, $uncertainState] = $make([['product_id' => $b, 'quantity' => 1]]);
    $p = demoCoin($uncertainKey, $uncertainState, 100)['body'];
    $s = demoDelivery($uncertainKey, $p, 'start')['body'];
    $uncertain = demoDelivery($uncertainKey, $s, 'no_confirmation')['body'];
    demoAssert($uncertain['order']['order_status'] === 'needs_review' && $uncertain['order']['items'][0]['dispensed_quantity'] === 0 && $stockOf($b) === 20, 'Missing sensor confirmation records no delivery.');

    [$noChangeKey, $noChange] = $make([['product_id' => $a, 'quantity' => 1]]);
    demoAssert(demoCoin($noChangeKey, $noChange, 1000)['status'] === 409, 'Overpayment is rejected without change.');
    demoAssert(demoHttp('demo-order', $noChangeKey)['body']['order']['amount_paid_centavos'] === 0, 'Rejected overpayment preserves balance.');

    [$shortKey, $shortState] = $make([['product_id' => $b, 'quantity' => 2]]);
    $p = demoCoin($shortKey, $shortState, 100)['body'];
    $p = demoCoin($shortKey, $p, 100)['body'];
    $setStock = $database->prepare('UPDATE products SET stock_quantity = ? WHERE id = ?');
    $setStock->execute([1, $b]);
    $short = demoDelivery($shortKey, $p, 'start')['body'];
    demoAssert($short['order']['order_status'] === 'needs_review' && $stockOf($b) === 1, 'Stock is rechecked before starting.');

    [$raceKey, $raceState] = $make([['product_id' => $b, 'quantity' => 1]]);
    $p = demoCoin($raceKey, $raceState, 100)['body'];
    $s = demoDelivery($raceKey, $p, 'start')['body'];
    $setStock->execute([0, $b]);
    $race = demoDelivery($raceKey, $s, 'delivered', $b)['body'];
    demoAssert($race['order']['order_status'] === 'needs_review' && $stockOf($b) === 0 && $race['order']['items'][0]['dispensed_quantity'] === 0, 'Stock cannot become negative after a race.');

    [$realKey, $realState] = $make([['product_id' => $real, 'quantity' => 1]]);
    $setDemo = $database->prepare('UPDATE transactions SET is_demo = 0 WHERE reference = ?'); $setDemo->execute([$realState['order']['reference']]);
    demoAssert(demoCoin($realKey, $realState, 500)['status'] === 403 && demoDelivery($realKey, $realState, 'start')['status'] === 403, 'Real orders cannot use simulation controls.');

    [$realProductKey, $realProductState] = $make([['product_id' => $real, 'quantity' => 1]]);
    $p = demoCoin($realProductKey, $realProductState, 500)['body'];
    $review = demoDelivery($realProductKey, $p, 'start')['body'];
    demoAssert($review['order']['order_status'] === 'needs_review' && $stockOf($real) === 3, 'Simulation cannot consume real-product inventory.');

    [$parallelKey, $parallelState] = $make([['product_id' => $a, 'quantity' => 1]]);
    demoAssert(demoParallel('demo-payment', $parallelKey, ['state_token' => $parallelState['state_token'], 'coin_centavos' => 500]) === [200, 409, 409, 409, 409, 409], 'Concurrent copies of a coin event can be credited only once.');
    $p = demoHttp('demo-order', $parallelKey)['body'];
    demoAssert($p['order']['amount_paid_centavos'] === 500, 'Concurrent coins must not over-credit.');
    $s = demoDelivery($parallelKey, $p, 'start')['body'];
    $stockBefore = $stockOf($a);
    demoAssert(demoParallel('demo-dispense', $parallelKey, ['state_token' => $s['state_token'], 'action' => 'delivered', 'product_id' => $a]) === [200, 409, 409, 409, 409, 409], 'Concurrent copies of a delivery event can consume only one unit.');
    demoAssert($stockOf($a) === $stockBefore - 1, 'Concurrent duplicate delivery decrements exactly once.');

    echo 'PASS: demo-only coins, no change, duplicate guards, confirmed-unit stock/count atomicity, jams, missing confirmation, stock races and completion.' . PHP_EOL;
} catch (Throwable $error) {
    $failed = true; fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
} finally {
    if ($database->inTransaction()) $database->rollBack();
    $database->beginTransaction();
    $deleteItems = $database->prepare('DELETE ti FROM transaction_items ti JOIN transactions t ON ti.transaction_id = t.id WHERE t.reference = ?');
    $deleteOrder = $database->prepare('DELETE FROM transactions WHERE reference = ?');
    foreach ($owned as $reference) { $deleteItems->execute([$reference]); $deleteOrder->execute([$reference]); }
    $deleteProduct = $database->prepare('DELETE FROM products WHERE id = ? AND sku LIKE ?');
    foreach ($fixtureIds as $id) $deleteProduct->execute([$id, $tag . '-%']);
    $database->commit();
    if ($original !== $database->query('SELECT * FROM products ORDER BY id')->fetchAll()
        || $ordersBefore !== (int) $database->query('SELECT COUNT(*) FROM transactions')->fetchColumn()
        || $itemsBefore !== (int) $database->query('SELECT COUNT(*) FROM transaction_items')->fetchColumn()) {
        $failed = true; fwrite(STDERR, 'FAIL: Original rows were not preserved.' . PHP_EOL);
    } else echo 'Only temporary simulation fixtures removed. Original data preserved.' . PHP_EOL;
}
exit($failed ? 1 : 0);
