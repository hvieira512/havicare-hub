<?php

declare(strict_types=1);

namespace Hub\Runtime;

use React\EventLoop\LoopInterface;

/**
 * Periodic housekeeping: retrying and expiring queued commands, and dropping
 * devices/connections that have gone quiet.
 */
final class MaintenanceScheduler
{
    private const INTERVAL_SECONDS = 10;
    private const COMMAND_RETRY_AFTER_SECONDS = 60;
    private const COMMAND_MAX_ATTEMPTS = 3;

    /**
     * @param array<string, mixed> $dashboardConfig the `dashboard` section of the hub config
     */
    public static function schedule(LoopInterface $loop, HubServices $services, array $dashboardConfig): void
    {
        $commandTimeout = (int)$dashboardConfig['command_timeout_seconds'];
        $deviceIdleTimeout = (int)$dashboardConfig['device_idle_timeout_seconds'];
        $store = $services->dashboardStore;
        $hubServer = $services->hubServer;

        $loop->addPeriodicTimer(
            self::INTERVAL_SECONDS,
            static function () use ($store, $hubServer, $commandTimeout, $deviceIdleTimeout): void {
                $store->retryWaitingCommands(
                    self::COMMAND_RETRY_AFTER_SECONDS,
                    $commandTimeout,
                    self::COMMAND_MAX_ATTEMPTS,
                    static fn (string $imei, string $bytes, array $command): string
                        => $hubServer->submitDownlink($imei, $bytes)
                );
                $store->expireWaitingCommands($commandTimeout);
                $store->expireStaleDevices($deviceIdleTimeout);
            }
        );

        $loop->addPeriodicTimer(
            self::INTERVAL_SECONDS,
            static function () use ($hubServer, $deviceIdleTimeout): void {
                $hubServer->expireIdleConnections($deviceIdleTimeout);
            }
        );
    }
}
