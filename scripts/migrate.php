<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/database.php';

try {
    $database = medivendDatabase();
    foreach (['database/schema.sql', 'database/migrations/001_demo_coin_events.sql', 'database/updates/002_prices_10_pesos.sql',
        'database/migrations/003_twenty_peso_coins.sql', 'database/migrations/004_hardware_boundaries.sql'] as $file) {
        $sql = file_get_contents(__DIR__ . '/../' . $file);
        if ($sql === false) throw new RuntimeException('Unable to read migration: ' . $file);
        $database->exec($sql);
        echo 'Applied: ' . $file . PHP_EOL;
    }
    echo 'Demo coin-event storage and current product prices are ready. Existing orders remain unchanged.' . PHP_EOL;
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
