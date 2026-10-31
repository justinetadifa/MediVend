<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/demo-order.php';
require_once __DIR__ . '/../includes/accepted-coin.php';

function coinAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function coinRequestHandle(string $path, string $key, ?array $body = null, ?string $lookup = null)
{
    $url = rtrim(getenv('MEDIVEND_TEST_BASE_URL') ?: 'http://localhost/MediVend', '/') . '/api/' . $path . '.php';
    $headers = ['Content-Type: application/json', 'X-Demo-Order-Key: ' . $key];
    if ($lookup !== null) $headers[] = 'X-Demo-Event-ID: ' . $lookup;
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) { curl_setopt($handle, CURLOPT_POST, true); curl_setopt($handle, CURLOPT_POSTFIELDS, $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR)); }
    return $handle;
}
function coinResponse($handle): array
{
    $body = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
    coinAssert(is_string($body), 'Start Apache and MySQL before running the test.');
    return ['status' => $status, 'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
}
function coinSend(string $key, int $coin, ?string $eventId = null): array
{
    return coinResponse(coinRequestHandle('demo-payment', $key, ['event_id' => $eventId ?? bin2hex(random_bytes(32)), 'coin_centavos' => $coin]));
}
function coinParallel(string $key, array $events): array
{
    $multi = curl_multi_init(); $handles = [];
    foreach ($events as $event) { $handle = coinRequestHandle('demo-payment', $key, $event); $handles[] = $handle; curl_multi_add_handle($multi, $handle); }
    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, .2); } while ($running);
    $results = [];
    foreach ($handles as $handle) {
        $results[] = ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => json_decode(curl_multi_getcontent($handle), true, 512, JSON_THROW_ON_ERROR)];
        curl_multi_remove_handle($multi, $handle); curl_close($handle);
    }
    curl_multi_close($multi); return $results;
}

function raceCloseAndCoin(string $key): array
{
    $handles = [coinRequestHandle('demo-session', $key, []), coinRequestHandle('demo-payment', $key,
        ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 100])];
    $multi = curl_multi_init();
    foreach ($handles as $handle) curl_multi_add_handle($multi, $handle);
    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, .2); } while ($running);
    $results = [];
    foreach ($handles as $handle) {
        $results[] = ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => json_decode(curl_multi_getcontent($handle), true, 512, JSON_THROW_ON_ERROR)];
        curl_multi_remove_handle($multi, $handle); curl_close($handle);
    }
    curl_multi_close($multi); return $results;
}

function acceptedCoinParallel(string $reference, array $events): array
{
    $script = 'require ' . var_export(__DIR__ . '/../includes/database.php', true) . '; require '
        . var_export(__DIR__ . '/../includes/accepted-coin.php', true) . '; '
        . 'echo json_encode(medivendRecordAcceptedCoin(medivendDatabase(), $argv[1], $argv[2], (int) $argv[3]), JSON_THROW_ON_ERROR);';
    $children = [];
    foreach ($events as $event) {
        $process = proc_open([PHP_BINARY, '-r', $script, $reference, $event['event_id'], (string) $event['coin_centavos']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        coinAssert(is_resource($process), 'Could not start accepted-coin simulation.');
        fclose($pipes[0]); $children[] = [$process, $pipes];
    }
    $results = [];
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        coinAssert(proc_close($process) === 0, 'Accepted-coin simulation failed: ' . $errors);
        $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
    return $results;
}

$database = medivendDatabase();
$originalProducts = $database->query('SELECT * FROM products ORDER BY id')->fetchAll();
$originalOrders = $database->query('SELECT * FROM transactions ORDER BY id')->fetchAll();
$originalItems = $database->query('SELECT * FROM transaction_items ORDER BY id')->fetchAll();
$originalCoins = $database->query('SELECT * FROM demo_coin_events ORDER BY id')->fetchAll();
$owned = []; $ids = []; $failed = false; $tag = 'TEST-COIN-' . bin2hex(random_bytes(5));
try {
    $used = array_fill_keys(array_column($originalProducts, 'compartment_number'), true); $slots = [];
    for ($slot = 1; $slot < 65536 && count($slots) < 2; $slot++) if (!isset($used[$slot])) $slots[] = $slot;
    coinAssert(count($slots) === 2, 'Need two fixture compartments.');
    $insert = $database->prepare('INSERT INTO products (sku, name, price_centavos, stock_quantity, compartment_number, is_demo) VALUES (?, ?, 1000, 30, ?, 1)');
    foreach ($slots as $index => $slot) { $insert->execute([$tag . '-' . $index, 'Coin test medicine ' . $index, $slot]); $ids[] = (int) $database->lastInsertId(); }
    $make = static function (int $quantity = 1, bool $multiple = false) use ($database, $ids, &$owned): array {
        $key = bin2hex(random_bytes(32));
        $items = [['product_id' => $ids[0], 'quantity' => $quantity]];
        if ($multiple) $items[] = ['product_id' => $ids[1], 'quantity' => 1];
        $saved = medivendSaveOrder($database, $items, $key);
        $owned[] = $saved['order']['reference']; return [$key, $saved['order']];
    };
    $eventsFor = static function (string $reference) use ($database): array {
        $query = $database->prepare('SELECT ce.* FROM demo_coin_events ce JOIN transactions t ON ce.transaction_id = t.id WHERE t.reference = ? ORDER BY ce.id');
        $query->execute([$reference]); return $query->fetchAll();
    };

    [$key, $order] = $make(2, true);
    coinAssert($order['total_centavos'] === 3000, 'Multiple items and quantities must use PHP/database pricing.');
    $firstId = bin2hex(random_bytes(32));
    foreach ([100, 500, 1000, 1000, 100, 100, 100, 100] as $index => $coin) {
        $result = coinSend($key, $coin, $index === 0 ? $firstId : null);
        coinAssert($result['status'] === 200 && $result['body']['coin_event']['outcome'] === 'accepted', 'Mixed denominations must be accepted.');
        coinAssert($result['body']['order']['payment_status'] === ($index === 7 ? 'paid' : 'partial'), 'Payment remains partial until exactly reached.');
    }
    coinAssert($result['body']['order']['amount_paid_centavos'] === 3000 && count($eventsFor($order['reference'])) === 8, 'Exactly 3000 centavos in eight events.');
    $retry = coinSend($key, 100, $firstId);
    coinAssert($retry['status'] === 200 && $retry['body']['coin_event']['replayed'] === true && $retry['body']['order']['amount_paid_centavos'] === 3000, 'Accepted event can replay after full payment without adding credit.');
    $extraId = bin2hex(random_bytes(32));
    $extra = coinSend($key, 100, $extraId);
    coinAssert($extra['status'] === 409 && $extra['body']['error']['code'] === 'payment_closed', 'A new event after payment is rejected and recorded.');
    $extraRetry = coinSend($key, 100, $extraId);
    coinAssert($extraRetry['status'] === 409 && $extraRetry['body']['coin_event']['replayed'] === true && count($eventsFor($order['reference'])) === 9, 'Rejected event retries preserve one ledger result.');
    coinAssert(coinSend($key, 500, $firstId)['status'] === 409, 'An event ID cannot change denomination.');
    [$otherKey] = $make();
    coinAssert(coinSend($otherKey, 100, $firstId)['status'] === 409, 'An event ID cannot credit another order.');

    [$overKey, $overOrder] = $make();
    coinSend($overKey, 500); coinSend($overKey, 100);
    $overId = bin2hex(random_bytes(32));
    $over = coinSend($overKey, 500, $overId);
    coinAssert($over['status'] === 409 && $over['body']['order']['amount_paid_centavos'] === 600, 'Overpayment never increases the balance.');
    coinAssert(strpos($over['body']['error']['message'], 'simulated ₱1') !== false && strpos($over['body']['error']['message'], MEDIVEND_EXACT_PAYMENT_MESSAGE) !== false, 'Overpayment explains smaller supported denominations.');
    coinAssert($over['body']['usable_denominations_centavos'] === [100], 'Current usable coins are returned.');
    $lookup = coinResponse(coinRequestHandle('demo-order', $overKey, null, $overId));
    coinAssert($lookup['body']['coin_event']['outcome'] === 'rejected', 'A refresh can recover a rejected coin outcome.');
    $lookup = coinResponse(coinRequestHandle('demo-order', $overKey, null, bin2hex(random_bytes(32))));
    coinAssert($lookup['body']['coin_event'] === null, 'A missing event is distinguishable from accepted credit.');

    [$parallelKey, $parallelOrder] = $make(3);
    $duplicateEvent = ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 100];
    $parallel = coinParallel($parallelKey, array_fill(0, 10, $duplicateEvent));
    coinAssert(array_column($parallel, 'status') === array_fill(0, 10, 200), 'Concurrent copies all return the one persistent result.');
    coinAssert(count($eventsFor($parallelOrder['reference'])) === 1, 'Concurrent duplicate coin has exactly one ledger row.');
    $unique = [];
    for ($i = 0; $i < 9; $i++) $unique[] = ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 100];
    $parallel = coinParallel($parallelKey, $unique);
    coinAssert(array_column($parallel, 'status') === array_fill(0, 9, 200), 'Distinct simultaneous coins each count once.');
    $status = coinResponse(coinRequestHandle('demo-order', $parallelKey));
    coinAssert($status['body']['order']['amount_paid_centavos'] === 1000 && $status['body']['order']['payment_status'] === 'partial', 'Concurrent balance is exact.');

    [$raceKey, $raceOrder] = $make();
    $raceEvents = [];
    for ($i = 0; $i < 5; $i++) $raceEvents[] = ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 500];
    $race = coinParallel($raceKey, $raceEvents);
    $statuses = array_column($race, 'status'); sort($statuses);
    coinAssert($statuses === [200, 200, 409, 409, 409], 'Simultaneous payments cannot exceed the total.');
    coinAssert(count($eventsFor($raceOrder['reference'])) === 5, 'Accepted and rejected race events are recorded persistently.');
    $check = $database->prepare('SELECT amount_paid_centavos FROM transactions WHERE reference = ?'); $check->execute([$raceOrder['reference']]);
    coinAssert((int) $check->fetchColumn() === 1000, 'No race can over-credit an order.');

    [$legacyKey, $legacyOrder] = $make();
    $legacy = $database->prepare("UPDATE transactions SET amount_paid_centavos = 400, payment_status = 'partial' WHERE reference = ?"); $legacy->execute([$legacyOrder['reference']]);
    $legacyPaid = coinSend($legacyKey, 100);
    coinAssert($legacyPaid['body']['order']['amount_paid_centavos'] === 500, 'Existing pre-ledger credit is preserved.');

    [$twentyKey, $twentyOrder] = $make(2);
    $twentyEvent = ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 2000];
    $twenties = coinParallel($twentyKey, array_fill(0, 6, $twentyEvent));
    coinAssert(array_column($twenties, 'status') === array_fill(0, 6, 200), 'Concurrent ₱20 duplicates replay successfully.');
    $twenty = coinResponse(coinRequestHandle('demo-order', $twentyKey, null, $twentyEvent['event_id']));
    coinAssert($twenty['body']['order']['amount_paid_centavos'] === 2000 && $twenty['body']['order']['payment_status'] === 'paid'
        && count($eventsFor($twentyOrder['reference'])) === 1, 'One ₱20 event exactly pays a ₱20 order once and recovers on refresh.');
    coinAssert($twenty['body']['accepted_denominations_centavos'] === [100, 500, 1000, 2000]
        && $twenty['body']['exact_payment_message'] === MEDIVEND_EXACT_PAYMENT_MESSAGE, 'All denominations and exact-payment warning are returned.');
    [$mixedKey] = $make(4);
    foreach ([2000, 1000, 500, 100, 100, 100, 100, 100] as $coin) $mixed = coinSend($mixedKey, $coin);
    coinAssert($mixed['body']['order']['amount_paid_centavos'] === 4000 && $mixed['body']['order']['payment_status'] === 'paid', 'All four denominations can combine to exactly pay ₱40.');
    [$tenKey, $tenOrder] = $make();
    $tooLarge = coinSend($tenKey, 2000);
    coinAssert($tooLarge['status'] === 409 && $tooLarge['body']['order']['amount_paid_centavos'] === 0
        && $tooLarge['body']['usable_denominations_centavos'] === [100, 500, 1000], 'Demo ₱20 on ₱10 is recorded as rejected without credit.');
    [$partialKey] = $make(2);
    coinSend($partialKey, 500); $partialId = bin2hex(random_bytes(32));
    $partialOver = coinSend($partialKey, 2000, $partialId);
    coinAssert($partialOver['status'] === 409 && $partialOver['body']['order']['amount_paid_centavos'] === 500
        && $partialOver['body']['order']['payment_status'] === 'partial', 'Demo ₱20 cannot exceed the ₱15 balance after partial payment.');
    coinAssert(coinSend($partialKey, 2000, $partialId)['body']['coin_event']['replayed'] === true, 'Rejected ₱20 retries stay rejected.');

    // Simulate trusted acceptor observations by calling the internal recorder.
    // No real acceptor, serial port, motor or public hardware route is used.
    $acceptedId = bin2hex(random_bytes(32));
    $accepted = medivendRecordAcceptedCoin($database, $tenOrder['reference'], $acceptedId, 2000);
    coinAssert($accepted['order']['amount_paid_centavos'] === 2000 && $accepted['order']['order_status'] === 'needs_review'
        && $accepted['order']['dispensing_status'] === 'needs_review', 'An already accepted excess coin records its full ₱20 and blocks dispensing.');
    coinAssert($accepted['coin_event']['event_source'] === 'accepted_coin' && $accepted['coin_event']['outcome'] === 'accepted', 'Observed accepted money is never logged as a rejected demo coin.');
    coinAssert(medivendRecordAcceptedCoin($database, $tenOrder['reference'], $acceptedId, 2000)['coin_event']['replayed'] === true, 'An accepted-coin retry credits only once.');
    $acceptedView = coinResponse(coinRequestHandle('demo-order', $tenKey));
    coinAssert($acceptedView['body']['excess_centavos'] === 1000 && $acceptedView['body']['order']['amount_paid_centavos'] === 2000, 'Refresh returns full credit and excess, without capping at the total.');
    coinAssert(coinResponse(coinRequestHandle('demo-dispense', $tenKey,
        ['state_token' => $acceptedView['body']['state_token'], 'action' => 'start', 'product_id' => null]))['status'] === 409, 'An overpaid order cannot automatically dispense.');
    coinAssert(coinResponse(coinRequestHandle('demo-session', $tenKey, []))['status'] === 409, 'Excess credit cannot be reset.');
    coinAssert(coinSend($tenKey, 2000, $acceptedId)['status'] === 409, 'The demo path cannot replay or forge an accepted-coin source.');
    [$acceptedPartialKey, $acceptedPartialOrder] = $make(2);
    coinSend($acceptedPartialKey, 500);
    $acceptedPartial = medivendRecordAcceptedCoin($database, $acceptedPartialOrder['reference'], bin2hex(random_bytes(32)), 2000);
    coinAssert($acceptedPartial['order']['amount_paid_centavos'] === 2500 && $acceptedPartial['order']['order_status'] === 'needs_review', 'Already accepted overpayment after partial credit preserves all ₱25.');
    [$acceptedRaceKey, $acceptedRaceOrder] = $make(2);
    $acceptedEvent = ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 2000];
    $acceptedCopies = acceptedCoinParallel($acceptedRaceOrder['reference'], array_fill(0, 6, $acceptedEvent));
    coinAssert(count(array_filter($acceptedCopies, static fn(array $result): bool => !$result['coin_event']['replayed'])) === 1
        && count($eventsFor($acceptedRaceOrder['reference'])) === 1, 'Concurrent accepted-coin copies have one credit and ledger row.');
    $observed = [];
    for ($i = 0; $i < 3; $i++) $observed[] = ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 2000];
    acceptedCoinParallel($acceptedRaceOrder['reference'], $observed);
    $observedState = coinResponse(coinRequestHandle('demo-order', $acceptedRaceKey))['body']['order'];
    coinAssert($observedState['amount_paid_centavos'] === 8000 && $observedState['order_status'] === 'needs_review', 'Distinct already accepted coins after paid are all preserved and require staff.');

    foreach ([['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => '100'], ['event_id' => 'bad', 'coin_centavos' => 100], ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => -100], ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 200], ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 100, 'total_centavos' => 1], ['event_id' => bin2hex(random_bytes(32)), 'coin_centavos' => 2000, 'event_source' => 'accepted_coin']] as $invalid) {
        coinAssert(coinResponse(coinRequestHandle('demo-payment', $overKey, $invalid))['status'] === 422, 'Invalid values/fields are rejected.');
    }
    foreach ($owned as $reference) {
        $rows = $eventsFor($reference); foreach ($rows as $row) {
            coinAssert((int) $row['amount_after_centavos'] - (int) $row['amount_before_centavos'] === ($row['outcome'] === 'accepted' ? (int) $row['coin_centavos'] : 0), 'Every ledger row records an exact integer transition.');
        }
    }
    coinAssert(coinResponse(coinRequestHandle('demo-session', $key, []))['status'] === 409, 'Reset cannot cancel a fully paid order.');
    coinAssert(coinResponse(coinRequestHandle('demo-session', $overKey, []))['status'] === 409, 'Reset cannot clear partial credit.');
    [$closeKey] = $make();
    $closed = coinResponse(coinRequestHandle('demo-session', $closeKey, []));
    coinAssert($closed['status'] === 200 && $closed['body']['order']['order_status'] === 'cancelled'
        && $closed['body']['order']['amount_paid_centavos'] === 0, 'Only an unpaid saved session can close.');
    coinAssert(coinResponse(coinRequestHandle('demo-session', $closeKey, []))['status'] === 200, 'Closing an unpaid session is idempotent.');
    coinAssert(coinSend($closeKey, 100)['status'] === 409, 'A closed unpaid session cannot receive later credit.');
    for ($attempt = 0; $attempt < 6; $attempt++) {
        [$closeRaceKey] = $make();
        [$closeRace, $coinRace] = raceCloseAndCoin($closeRaceKey);
        coinAssert([$closeRace['status'], $coinRace['status']] === [200, 409]
            || [$closeRace['status'], $coinRace['status']] === [409, 200], 'Reset and payment cannot both succeed in a race.');
        $status = coinResponse(coinRequestHandle('demo-order', $closeRaceKey))['body']['order'];
        coinAssert(($status['order_status'] === 'cancelled' && $status['amount_paid_centavos'] === 0)
            || ($status['order_status'] === 'pending' && $status['amount_paid_centavos'] === 100), 'A concurrent coin is either preserved or not accepted.');
    }
    [$processingKey, $processingOrder] = $make(2);
    $processingPayment = coinSend($processingKey, 2000);
    $start = coinResponse(coinRequestHandle('demo-dispense', $processingKey,
        ['state_token' => $processingPayment['body']['state_token'], 'action' => 'start', 'product_id' => null]));
    coinAssert($start['body']['order']['order_status'] === 'processing', 'Fixture enters unresolved dispensing.');
    coinAssert(coinResponse(coinRequestHandle('demo-session', $processingKey, []))['status'] === 409, 'Reset cannot interrupt unresolved dispensing.');
    $one = coinResponse(coinRequestHandle('demo-dispense', $processingKey,
        ['state_token' => $start['body']['state_token'], 'action' => 'delivered', 'product_id' => $ids[0]]));
    $processingExcess = medivendRecordAcceptedCoin($database, $processingOrder['reference'], bin2hex(random_bytes(32)), 100);
    coinAssert($processingExcess['order']['amount_paid_centavos'] === 2100 && $processingExcess['order']['order_status'] === 'needs_review'
        && $processingExcess['order']['items'][0]['dispensed_quantity'] === 1, 'Accepted excess during dispensing stops and preserves partial delivery.');
    $stopped = coinResponse(coinRequestHandle('demo-order', $processingKey));
    coinAssert(coinResponse(coinRequestHandle('demo-dispense', $processingKey,
        ['state_token' => $stopped['body']['state_token'], 'action' => 'delivered', 'product_id' => $ids[0]]))['status'] === 409, 'Accepted excess prevents the next delivery.');
    echo 'PASS: ₱1/₱5/₱10/₱20, mixed/exact payments, demo overpayment rejection, accepted excess-credit review, durable replay, concurrent events, refresh, and session protection.' . PHP_EOL;
} catch (Throwable $error) { $failed = true; fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL); }
finally {
    if ($database->inTransaction()) $database->rollBack(); $database->beginTransaction();
    $coins = $database->prepare('DELETE ce FROM demo_coin_events ce JOIN transactions t ON ce.transaction_id = t.id WHERE t.reference = ?');
    $items = $database->prepare('DELETE ti FROM transaction_items ti JOIN transactions t ON ti.transaction_id = t.id WHERE t.reference = ?');
    $orders = $database->prepare('DELETE FROM transactions WHERE reference = ?');
    foreach ($owned as $reference) { $coins->execute([$reference]); $items->execute([$reference]); $orders->execute([$reference]); }
    $products = $database->prepare('DELETE FROM products WHERE id = ? AND sku LIKE ?');
    foreach ($ids as $id) $products->execute([$id, $tag . '-%']); $database->commit();
    if ($originalProducts !== $database->query('SELECT * FROM products ORDER BY id')->fetchAll()
        || $originalOrders !== $database->query('SELECT * FROM transactions ORDER BY id')->fetchAll()
        || $originalItems !== $database->query('SELECT * FROM transaction_items ORDER BY id')->fetchAll()
        || $originalCoins !== $database->query('SELECT * FROM demo_coin_events ORDER BY id')->fetchAll()) {
        $failed = true; fwrite(STDERR, 'FAIL: Original data was not preserved.' . PHP_EOL);
    } else echo 'Only temporary coin-test data removed; original rows are unchanged.' . PHP_EOL;
}
exit($failed ? 1 : 0);
