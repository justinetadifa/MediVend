<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/checkout.php';

function medivendMachineConfig(): array
{
    $config = require __DIR__ . '/../config/machine.php';
    if (!in_array($config['mode'], ['simulation', 'hardware'], true)) throw new RuntimeException('Invalid server machine mode.');
    return $config;
}

function medivendMachineStatus(): array
{
    $status = ['mode' => 'hardware', 'available' => false, 'message' => 'Machine unavailable',
        'accepted_denominations_centavos' => [100, 500, 1000, 2000], 'unit_price_centavos' => 1000,
        'exact_payment_message' => 'Exact payment only. No change.', 'active_order' => false];
    try {
        $config = medivendMachineConfig();
        $status['mode'] = $config['mode'];
        if ($config['mode'] === 'simulation') return array_replace($status, ['available' => true, 'message' => 'Simulation mode']);
        $database = medivendDatabase();
        $row = $database->query('SELECT active_transaction_id, health, config_hash, TIMESTAMPDIFF(SECOND, heartbeat_at, CURRENT_TIMESTAMP) AS age FROM hardware_machine_state WHERE id = 1')->fetch();
        $status['active_order'] = $row && $row['active_transaction_id'] !== null;
        // A config boolean is never evidence of connected hardware. Only the
        // CLI worker can publish a fresh, verified adapter health observation.
        $configured = is_string($config['adapter_file']) && is_file($config['adapter_file']) && is_readable($config['adapter_file'])
            && $config['delivery_timeout_seconds'] > 0 && $config['delivery_timeout_seconds'] <= 86400;
        $status['available'] = $configured && $row && $row['health'] === 'ready'
            && hash_equals(medivendHardwareConfigHash($config), (string) $row['config_hash'])
            && $row['age'] !== null && (int) $row['age'] >= 0 && (int) $row['age'] <= $config['heartbeat_timeout_seconds'];
        if ($status['available']) {
            $badPrices = (int) $database->query('SELECT COUNT(*) FROM products WHERE is_active = 1 AND is_demo = 0 AND price_centavos <> 1000')->fetchColumn();
            $reviews = (int) $database->query("SELECT COUNT(*) FROM transactions WHERE is_demo = 0 AND (order_status = 'needs_review' OR dispensing_status = 'needs_review')")->fetchColumn();
            if ($badPrices > 0 || $reviews > 0) $status['available'] = false;
        }
    } catch (Throwable $error) { error_log('[MediVend machine] ' . $error->getMessage()); }
    return $status;
}

function medivendHardwareConfigHash(array $config): string
{
    $digest = $config['adapter_digest'] ?? ($config['adapter_file'] && is_readable($config['adapter_file']) ? hash_file('sha256', $config['adapter_file']) : '');
    return hash('sha256', json_encode([$config['mode'],$config['adapter_file'],$config['delivery_timeout_seconds'],$digest], JSON_THROW_ON_ERROR));
}

function medivendRequireSimulation(): void
{
    if (medivendMachineConfig()['mode'] !== 'simulation') {
        throw new MedivendCheckoutError(403, 'simulation_disabled', 'Machine unavailable. Please contact clinic staff.');
    }
}

function medivendRequireHardwareReady(): void
{
    $status = medivendMachineStatus();
    if ($status['mode'] !== 'hardware' || !$status['available']) {
        throw new MedivendCheckoutError(503, 'machine_unavailable', 'Machine unavailable. Please contact clinic staff.');
    }
}
