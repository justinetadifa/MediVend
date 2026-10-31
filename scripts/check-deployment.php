<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/machine.php';
try {
    $database = medivendDatabase();
    foreach (['products','transactions','transaction_items','demo_coin_events','hardware_machine_state','hardware_commands','hardware_delivery_events'] as $table) $database->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
    $unsafe = $database->prepare('SELECT COUNT(*) FROM transactions WHERE total_centavos > ? OR amount_paid_centavos > ?'); $unsafe->execute([PHP_INT_MAX,PHP_INT_MAX]);
    if ((int) $unsafe->fetchColumn() > 0) throw new RuntimeException('Imported balances exceed this platform integer limit. Keep machine unavailable and reconcile on a compatible system.');
    $prices = $database->query("SELECT sku, price_centavos FROM products WHERE sku IN ('DEMO-CETI','DEMO-LOPE','DEMO-PARA')")->fetchAll();
    foreach ($prices as $product) if ((int) $product['price_centavos'] !== 1000) throw new RuntimeException('Current medicine price must be 1000 centavos.');
    echo 'PHP ' . PHP_VERSION . ', integer width ' . (PHP_INT_SIZE * 8) . " bits; PDO/database/migrations readable.\n";
    echo json_encode(medivendMachineStatus(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, 'Deployment check failed: ' . $error->getMessage() . PHP_EOL); exit(1); }
