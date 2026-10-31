<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../scripts/hardware-worker.php';
function hwAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
final class TestHardwareAdapter implements MedivendHardwareAdapter
{
    public array $events = [], $commands = [], $acknowledged = [];
    public int $stops = 0;
    public function health(): array { return array_fill_keys(['connected','coin_events_verified','sensor_events_verified','command_deduplication','safe_stop','persistent_event_ids'], true) + ['denominations_centavos' => [100,500,1000,2000]]; }
    public function verifiedEvents(): iterable { $events = $this->events; $this->events = []; return $events; }
    public function acknowledge(string $id): void { $this->acknowledged[] = $id; }
    public function dispatch(array $command): void { $this->commands[] = $command; }
    public function stop(): void { $this->stops++; }
}
function hwParallel(string $function, array $arguments): array
{
    $script = 'require ' . var_export(__DIR__ . '/../includes/hardware-flow.php', true) . '; echo json_encode(' . $function . '(medivendDatabase(), ...json_decode($argv[1], true)), JSON_THROW_ON_ERROR);';
    $children = [];
    for ($i = 0; $i < 6; $i++) {
        $process = proc_open([PHP_BINARY, '-r', $script, json_encode($arguments)], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        hwAssert(is_resource($process), 'Cannot start concurrent hardware boundary test.'); fclose($pipes[0]); $children[] = [$process,$pipes];
    }
    $results = [];
    foreach ($children as [$process,$pipes]) {
        $out = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        hwAssert(proc_close($process) === 0, $errors); $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
    return $results;
}
$database = medivendDatabase();
$tables = ['products','transactions','transaction_items','demo_coin_events','hardware_machine_state','hardware_commands','hardware_delivery_events'];
$snapshot = static function () use ($database, $tables): array { $rows = []; foreach ($tables as $table) $rows[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(); return $rows; };
$before = $snapshot();
hwAssert($before['hardware_machine_state'][0]['active_transaction_id'] === null, 'Run on an idle demo test database.');
$owned = []; $productId = null; $failed = false; $server = null; $tag = 'TEST-HW-' . bin2hex(random_bytes(6));
putenv('MEDIVEND_MODE=hardware');
try {
    hwAssert(!medivendMachineStatus()['available'], 'An unconfigured adapter must fail closed.');
    hwAssert(!medivendHardwareHealthVerified(['connected' => true]), 'Connected alone is insufficient verification.');
    $adapterFile=tempnam(sys_get_temp_dir(),'medivend-adapter-boundary-');
    file_put_contents($adapterFile,'<?php return new MedivendUnconfiguredAdapter();');
    $previousAdapter=getenv('MEDIVEND_HARDWARE_ADAPTER'); $previousTimeout=getenv('MEDIVEND_DELIVERY_TIMEOUT_SECONDS');
    putenv('MEDIVEND_HARDWARE_ADAPTER='.$adapterFile); putenv('MEDIVEND_DELIVERY_TIMEOUT_SECONDS=30');
    $database->exec("UPDATE hardware_machine_state SET health='ready', heartbeat_at=CURRENT_TIMESTAMP, config_hash=REPEAT('0',64) WHERE id=1");
    hwAssert(!medivendMachineStatus()['available'],'A ready flag without the worker configuration fingerprint is not readiness.');
    $stale=$database->prepare("UPDATE hardware_machine_state SET config_hash=?, heartbeat_at=CURRENT_TIMESTAMP - INTERVAL 1 DAY WHERE id=1"); $stale->execute([medivendHardwareConfigHash(medivendMachineConfig())]);
    hwAssert(!medivendMachineStatus()['available'],'A stale heartbeat cannot allow a purchase.');
    putenv($previousAdapter===false?'MEDIVEND_HARDWARE_ADAPTER':'MEDIVEND_HARDWARE_ADAPTER='.$previousAdapter);
    putenv($previousTimeout===false?'MEDIVEND_DELIVERY_TIMEOUT_SECONDS':'MEDIVEND_DELIVERY_TIMEOUT_SECONDS='.$previousTimeout);
    $used = array_fill_keys($database->query('SELECT compartment_number FROM products')->fetchAll(PDO::FETCH_COLUMN), true);
    for ($slot = 1; isset($used[$slot]); $slot++) {}
    $insert = $database->prepare('INSERT INTO products (sku, name, price_centavos, stock_quantity, compartment_number, is_demo) VALUES (?, ?, 1000, 30, ?, 0)');
    $insert->execute([$tag, 'Hardware boundary test', $slot]); $productId = (int) $database->lastInsertId();
    $make = static function (int $qty) use ($database, $productId, &$owned): array {
        // Resolve previous owned artificial scenarios for independent cases.
        // This is fixture management, not a customer staff-resolution API.
        $resolved = $database->prepare("UPDATE transactions SET order_status = 'failed', dispensing_status = 'failed' WHERE reference = ? AND order_status = 'needs_review'");
        foreach ($owned as $old) $resolved->execute([$old]);
        $database->exec('UPDATE hardware_machine_state SET active_transaction_id = NULL WHERE id = 1');
        $key = bin2hex(random_bytes(32)); $saved = medivendSaveOrder($database, [['product_id' => $productId, 'quantity' => $qty]], $key, false);
        $owned[] = $saved['order']['reference']; return [$key,$saved['order']['reference']];
    };
    $stock = static function () use ($database,$productId): int { return (int) $database->query('SELECT stock_quantity FROM products WHERE id = ' . $productId)->fetchColumn(); };
    $config = medivendMachineConfig(); $config['delivery_timeout_seconds'] = 30;
    [$key,$reference] = $make(2); $adapter = new TestHardwareAdapter();
    hwAssert(medivendSaveOrder($database,[['product_id'=>$productId,'quantity'=>2]],$key,false)['replayed'], 'Hardware checkout retries create one order.');
    try { medivendSaveOrder($database,[['product_id'=>$productId,'quantity'=>1]],bin2hex(random_bytes(32)),false); throw new RuntimeException('Second customer bypassed the active lease.'); }
    catch (MedivendCheckoutError $error) { hwAssert($error->errorCode === 'session_active','Active hardware session must protect credit even if browser storage is lost.'); }
    hwAssert(medivendQueueHardwareUnit($database,$reference) === null, 'Unpaid order cannot queue a motor.');
    $adapter->events[] = ['type'=>'accepted_coin','reference'=>$reference,'event_id'=>bin2hex(random_bytes(32)),'coin_centavos'=>2000];
    $initialStock = $stock(); medivendWorkerTick($database,$adapter,$config);
    hwAssert(count($adapter->commands) === 1 && $stock() === $initialStock, 'Dispatch alone is not delivery.');
    medivendWorkerTick($database,$adapter,$config); hwAssert(count($adapter->commands) === 1, 'A sent motor command cannot repeat.');
    $first = $adapter->commands[0]['id']; $event = ['type'=>'delivery','event_id'=>bin2hex(random_bytes(32)),'command_id'=>$first,'outcome'=>'sensor_confirmed'];
    $adapter->events[] = $event; medivendWorkerTick($database,$adapter,$config);
    hwAssert($stock() === $initialStock - 1 && count($adapter->commands) === 2, 'Only sensor confirmation consumes one unit and allows the next motor.');
    $adapter->events[] = $event; medivendWorkerTick($database,$adapter,$config);
    $adapter->events[] = array_replace($event,['event_id'=>bin2hex(random_bytes(32))]); medivendWorkerTick($database,$adapter,$config);
    hwAssert($stock() === $initialStock - 1 && count($adapter->commands) === 2, 'Same receipt and repeated sensor observations cannot deduct twice.');
    $adapter->events[] = array_replace($event,['event_id'=>bin2hex(random_bytes(32)),'command_id'=>$adapter->commands[1]['id']]); medivendWorkerTick($database,$adapter,$config);
    hwAssert(medivendReadOrder($database,$reference)['order_status'] === 'completed' && $stock() === $initialStock - 2, 'Completion requires every sensor-confirmed unit.');
    echo "PASS: verified-boundary payment, sequential unique commands, sensor-only atomic stock/counts, duplicate receipts, and completion.\n";

    foreach (['jam','timeout','uncertain'] as $fault) {
        [, $faultRef] = $make(2); medivendRecordAcceptedCoin($database,$faultRef,bin2hex(random_bytes(32)),2000);
        $a = new TestHardwareAdapter(); medivendWorkerTick($database,$a,$config); $faultStock = $stock();
        medivendHardwareDelivery($database,bin2hex(random_bytes(32)),$a->commands[0]['id'],'sensor_confirmed');
        medivendWorkerTick($database,$a,$config);
        if ($fault === 'timeout') {
            $expire = $database->prepare('UPDATE hardware_commands SET deadline_at = CURRENT_TIMESTAMP - INTERVAL 1 SECOND WHERE id = ?'); $expire->execute([$a->commands[1]['id']]);
        } else $a->events[] = ['type'=>'delivery','event_id'=>bin2hex(random_bytes(32)),'command_id'=>$a->commands[1]['id'],'outcome'=>$fault];
        medivendWorkerTick($database,$a,$config); medivendWorkerTick($database,$a,$config);
        $order = medivendReadOrder($database,$faultRef);
        hwAssert($order['order_status'] === 'needs_review' && $order['items'][0]['dispensed_quantity'] === 1 && $stock() === $faultStock - 1 && count($a->commands) === 2 && $a->stops > 0, 'Fault stops, retains credit/partial delivery, and never retries.');
        medivendHardwareDelivery($database,bin2hex(random_bytes(32)),$a->commands[1]['id'],'sensor_confirmed');
        hwAssert($stock() === $faultStock - 1, 'A late uncertain sensor event requires reconciliation, not automatic delivery.');
    }
    [$excessKey, $excessRef] = $make(1); $a = new TestHardwareAdapter();
    $coin = ['type'=>'accepted_coin','reference'=>$excessRef,'event_id'=>bin2hex(random_bytes(32)),'coin_centavos'=>2000];
    $a->events = [$coin,$coin]; medivendWorkerTick($database,$a,$config); $order = medivendReadOrder($database,$excessRef);
    hwAssert($order['amount_paid_centavos'] === 2000 && $order['order_status'] === 'needs_review' && count($a->commands) === 0, 'Physically accepted excess is retained once and blocks motors.');
    $database->exec('UPDATE hardware_machine_state SET active_transaction_id = NULL WHERE id = 1');
    medivendWorkerTick($database,$a,$config);
    hwAssert($database->query('SELECT health FROM hardware_machine_state WHERE id=1')->fetchColumn() === 'unavailable', 'Review credit blocks subsequent customers even after a closed lease.');
    [, $shortRef] = $make(1); medivendRecordAcceptedCoin($database,$shortRef,bin2hex(random_bytes(32)),1000);
    $stockBeforeShort = $stock(); $database->exec('UPDATE products SET stock_quantity=0 WHERE id=' . $productId); $shortAdapter = new TestHardwareAdapter();
    medivendWorkerTick($database,$shortAdapter,$config);
    hwAssert(count($shortAdapter->commands)===0 && $shortAdapter->stops>0 && medivendReadOrder($database,$shortRef)['order_status']==='needs_review','Stock recheck stops hardware before a motor command.');
    $database->exec('UPDATE products SET stock_quantity=' . $stockBeforeShort . ' WHERE id=' . $productId);
    echo "PASS: jams, timeout, uncertainty, late confirmations, preserved partial delivery, and full overpayment review.\n";

    [, $concurrentRef] = $make(1); medivendRecordAcceptedCoin($database,$concurrentRef,bin2hex(random_bytes(32)),1000);
    $command = medivendQueueHardwareUnit($database,$concurrentRef);
    $claims = hwParallel('medivendClaimHardwareCommand',[$command['id'],30]);
    hwAssert(count(array_filter($claims)) === 1, 'Concurrent command claims dispatch once.');
    $concurrentStock = $stock(); $receipts = hwParallel('medivendHardwareDelivery',[bin2hex(random_bytes(32)),$command['id'],'sensor_confirmed']);
    hwAssert($stock() === $concurrentStock - 1 && count(array_filter($receipts,static fn(array $r): bool => !$r['replayed'])) === 1, 'Concurrent same sensor event commits once.');
    [, $restartRef] = $make(1); medivendRecordAcceptedCoin($database,$restartRef,bin2hex(random_bytes(32)),1000);
    $command = medivendQueueHardwareUnit($database,$restartRef); medivendClaimHardwareCommand($database,$command['id'],30); medivendHardwareInterrupt($database);
    hwAssert(!medivendClaimHardwareCommand($database,$command['id'],30) && medivendReadOrder($database,$restartRef)['order_status'] === 'needs_review', 'Uncertain process restart cannot redispatch a motor.');
    echo "PASS: concurrent motor/sensor deduplication and conservative restart recovery.\n";

    // Isolated HTTP server: real local PHP/MySQL, hardware mode, no adapter.
    $port = random_int(21000,29000); $log = tempnam(sys_get_temp_dir(),'medivend-hw-http-');
    $server = proc_open([PHP_BINARY,'-S','127.0.0.1:' . $port,'-t',realpath(__DIR__ . '/..')], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes);
    hwAssert(is_resource($server),'Cannot start isolated HTTP test.'); fclose($pipes[0]);
    $request = static function (string $path, ?array $body = null, array $headers = []) use ($port): array {
        $curl = curl_init('http://127.0.0.1:' . $port . '/api/' . $path . '.php');
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'],$headers)]);
        if ($body !== null) { curl_setopt($curl,CURLOPT_POST,true); curl_setopt($curl,CURLOPT_POSTFIELDS,$body===[]?'{}':json_encode($body)); }
        $text=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        return [$status,$text ? json_decode($text,true,512,JSON_THROW_ON_ERROR) : null];
    };
    for ($attempt=0;$attempt<50;$attempt++) { [$status,$state]=$request('machine'); if ($status===200) break; usleep(50000); }
    hwAssert($status===200 && $state['mode']==='hardware' && !$state['available'],'Unconfigured hardware HTTP fails closed.');
    hwAssert($request('checkout',['items'=>[['product_id'=>$productId,'quantity'=>1]]],['Idempotency-Key: '.bin2hex(random_bytes(32))])[0]===503,'Checkout cannot bypass readiness.');
    foreach (['demo-payment','demo-dispense','demo-session','demo-order'] as $route) hwAssert($request($route,$route==='demo-order'?null:[])[0]===403,'Simulation route disabled in hardware mode.');
    hwAssert($request('order',null,['X-Order-Key: '.$key])[1]['order']['total_centavos']===2000,'Offline saved status remains readable by its capability.');
    hwAssert($request('session',[],['X-Order-Key: '.$excessKey])[0]===409,'Hardware session closure cannot clear credit/review.');
    $completedLease=$database->prepare('UPDATE hardware_machine_state SET active_transaction_id=(SELECT id FROM transactions WHERE reference=?) WHERE id=1'); $completedLease->execute([$reference]);
    hwAssert($request('session',[],['X-Order-Key: '.$key])[0]===200 && $database->query('SELECT active_transaction_id FROM hardware_machine_state WHERE id=1')->fetchColumn()===null,'Only completed confirmed hardware session hands off to next customer.');
    [$unpaidKey,$unpaidRef]=$make(1);
    hwAssert($request('session',[],['X-Order-Key: '.$unpaidKey])[0]===200 && medivendReadOrder($database,$unpaidRef)['order_status']==='cancelled','Locked unpaid hardware session can close safely.');
    echo "PASS: hardware API readiness block, disabled demo routes, and backend saved-status recovery.\n";
} catch (Throwable $error) { $failed=true; fwrite(STDERR,'FAIL: '.$error->getMessage().PHP_EOL); }
finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (isset($log)) unlink($log);
    if (isset($adapterFile)) unlink($adapterFile);
    if ($database->inTransaction()) $database->rollBack();
    $database->beginTransaction();
    $database->exec('UPDATE hardware_machine_state SET active_transaction_id = NULL WHERE id = 1');
    foreach ($owned as $reference) {
        foreach (["DELETE e FROM hardware_delivery_events e JOIN hardware_commands c ON c.id=e.command_id JOIN transactions t ON t.id=c.transaction_id WHERE t.reference=?",
            'DELETE c FROM hardware_commands c JOIN transactions t ON t.id=c.transaction_id WHERE t.reference=?',
            'DELETE e FROM demo_coin_events e JOIN transactions t ON t.id=e.transaction_id WHERE t.reference=?',
            'DELETE i FROM transaction_items i JOIN transactions t ON t.id=i.transaction_id WHERE t.reference=?', 'DELETE FROM transactions WHERE reference=?'] as $sql) {
            $query=$database->prepare($sql); $query->execute([$reference]);
        }
    }
    if ($productId) { $delete=$database->prepare('DELETE FROM products WHERE id=? AND sku=?'); $delete->execute([$productId,$tag]); }
    $restore=$database->prepare('UPDATE hardware_machine_state SET active_transaction_id=?, health=?, heartbeat_at=?, config_hash=? WHERE id=1'); $row=$before['hardware_machine_state'][0]; $restore->execute([$row['active_transaction_id'],$row['health'],$row['heartbeat_at'],$row['config_hash']]);
    $database->commit();
    if ($snapshot() !== $before) { $failed=true; fwrite(STDERR,"FAIL: Original database rows changed.\n"); }
    else echo "Only hardware test fixtures removed; original database rows are unchanged.\n";
}
exit($failed?1:0);
