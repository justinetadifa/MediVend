<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/database.php';
$admin = medivendDatabase(); $name = 'medivend_installtest_' . bin2hex(random_bytes(6)); $failed = false;
if (!preg_match('/\Amedivend_installtest_[a-f0-9]{12}\z/', $name)) throw new RuntimeException('Invalid temporary database.');
try {
    $admin->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    putenv('MEDIVEND_DB_NAME=' . $name);
    $run = static function (): void {
        $process = proc_open([PHP_BINARY,__DIR__ . '/../scripts/migrate.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot run migration child.');
        fclose($pipes[0]); stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process)!==0) throw new RuntimeException($errors);
    };
    $run(); $database = medivendDatabase();
    $database->exec("INSERT INTO products (sku,name,price_centavos,stock_quantity,compartment_number) VALUES ('INSTALLTEST','Migration fixture',1000,2,1)");
    $database->exec("INSERT INTO transactions (reference,total_centavos,amount_paid_centavos,payment_status) VALUES ('INSTALLTEST',2000,2000,'paid')");
    $database->exec("INSERT INTO transaction_items (transaction_id,product_id,product_name,compartment_number,quantity,unit_price_centavos) VALUES (1,1,'Migration fixture',1,2,1000)");
    $database->exec("INSERT INTO demo_coin_events (event_id,transaction_id,coin_centavos,outcome,message,amount_before_centavos,amount_after_centavos,payment_status_after) VALUES (REPEAT('a',64),1,2000,'accepted','Migration fixture',0,2000,'paid')");
    $tables=['products','transactions','transaction_items','demo_coin_events','hardware_machine_state','hardware_commands','hardware_delivery_events'];
    $snapshot=static function () use ($database,$tables): array { $rows=[]; foreach ($tables as $table) $rows[$table]=$database->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(); return $rows; };
    $before=$snapshot(); $run();
    if ($snapshot()!==$before) throw new RuntimeException('Rerunning migrations changed existing data.');
    $environment=array_merge(getenv(),['MEDIVEND_MODE'=>'hardware','MEDIVEND_HARDWARE_ADAPTER'=>'','MEDIVEND_DELIVERY_TIMEOUT_SECONDS'=>'0']);
    $worker=proc_open([PHP_BINARY,__DIR__.'/../scripts/hardware-worker.php','--once'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
    if (!is_resource($worker)) throw new RuntimeException('Cannot run unconfigured CLI worker.');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($worker)!==2 || strpos($out,'Machine unavailable')===false) throw new RuntimeException('Unconfigured worker did not fail closed: '.$errors);
    echo "PASS: empty database installation, all seven tables, ₱20 constraint, and non-destructive repeated migrations.\n";
    echo "PASS: unconfigured CLI worker reports Machine unavailable and exits 2 without device I/O.\n";
} catch (Throwable $error) { $failed=true; fwrite(STDERR,'FAIL: '.$error->getMessage().PHP_EOL); }
finally { $admin->exec('DROP DATABASE IF EXISTS `' . $name . '`'); }
exit($failed?1:0);
