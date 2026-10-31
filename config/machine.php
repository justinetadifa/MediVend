<?php
declare(strict_types=1);
require_once __DIR__ . '/environment.php';

// No browser/query-string switch. On the Orange Pi use the private server
// environment file shown in deploy/medivend.env.example.
return [
    'mode' => getenv('MEDIVEND_MODE') ?: 'simulation',
    'adapter_file' => getenv('MEDIVEND_HARDWARE_ADAPTER') ?: null,
    'delivery_timeout_seconds' => getenv('MEDIVEND_DELIVERY_TIMEOUT_SECONDS') !== false
        ? (int) getenv('MEDIVEND_DELIVERY_TIMEOUT_SECONDS') : 0,
    'heartbeat_timeout_seconds' => 10,
];
