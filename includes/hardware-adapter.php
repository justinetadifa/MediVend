<?php
declare(strict_types=1);

/** Domain boundary only. No GPIO pins, serial packets or pulse mapping assumed.
 * Implementations must authenticate/validate observations, persist event IDs
 * across reboots, and deduplicate motor command IDs at the device boundary.
 */
interface MedivendHardwareAdapter
{
    public function health(): array;
    public function verifiedEvents(): iterable;
    public function acknowledge(string $eventId): void;
    public function dispatch(array $command): void;
    public function stop(): void;
}

final class MedivendUnconfiguredAdapter implements MedivendHardwareAdapter
{
    public function health(): array { return ['connected' => false]; }
    public function verifiedEvents(): iterable { return []; }
    public function acknowledge(string $eventId): void { throw new RuntimeException('Hardware unconfigured.'); }
    public function dispatch(array $command): void { throw new RuntimeException('Machine unavailable.'); }
    public function stop(): void { /* No hardware attached. No physical stop is claimed. */ }
}

function medivendLoadHardwareAdapter(array $config): MedivendHardwareAdapter
{
    if (!$config['adapter_file']) return new MedivendUnconfiguredAdapter();
    // This is administrator-owned PHP outside DocumentRoot, never a URL or
    // customer-supplied path. Do not ship a pretend-ready production adapter.
    $path = realpath($config['adapter_file']);
    $root = realpath(__DIR__ . '/..');
    if (!$path || strpos(str_replace('\\', '/', $path), str_replace('\\', '/', $root) . '/') === 0) {
        throw new RuntimeException('Adapter must be a private file outside the web project.');
    }
    $adapter = require $path;
    if (!$adapter instanceof MedivendHardwareAdapter) throw new RuntimeException('Invalid hardware adapter.');
    return $adapter;
}

function medivendHardwareHealthVerified(array $health): bool
{
    foreach (['connected', 'coin_events_verified', 'sensor_events_verified', 'command_deduplication', 'safe_stop', 'persistent_event_ids'] as $capability) {
        if (($health[$capability] ?? false) !== true) return false;
    }
    return ($health['denominations_centavos'] ?? null) === [100, 500, 1000, 2000];
}
