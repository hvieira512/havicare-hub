<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Api\Services\DeviceService;
use Hub\Device\ConnectionAnnouncer;
use Hub\Device\DownlinkRetryContext;
use Hub\Log\Logger;
use React\EventLoop\LoopInterface;

/**
 * Manutenção periódica: repetir e expirar os comandos em fila, e largar os dispositivos e as
 * ligações que se calaram.
 */
final class MaintenanceScheduler
{
    private const INTERVAL_SECONDS = 10;
    private const COMMAND_RETRY_AFTER_SECONDS = 60;
    private const COMMAND_MAX_ATTEMPTS = 3;
    private const TIME_ZONE_INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $dashboardConfig a secção `dashboard` da configuração do hub
     */
    public static function schedule(LoopInterface $loop, HubServices $services, array $dashboardConfig): void
    {
        $commandTimeout = (int)$dashboardConfig['command_timeout_seconds'];
        $deviceIdleTimeout = (int)$dashboardConfig['device_idle_timeout_seconds'];
        $store = $services->deviceStore;
        $hubServer = $services->hubServer;
        $connections = new ConnectionAnnouncer($services->mqttBridge, $store);

        $loop->addPeriodicTimer(
            self::INTERVAL_SECONDS,
            static function () use ($store, $hubServer, $connections, $commandTimeout, $deviceIdleTimeout): void {
                $store->retryWaitingCommands(
                    self::COMMAND_RETRY_AFTER_SECONDS,
                    $commandTimeout,
                    self::COMMAND_MAX_ATTEMPTS,
                    // O contexto volta com a repetição. Sem ele, o comando reentra na fila
                    // sem o valor que o gateway precisa de executar.
                    static fn (string $imei, string $bytes, array $command): string
                        => $hubServer->submitDownlink($imei, $bytes, DownlinkRetryContext::forCommand($command))
                );
                $store->expireWaitingCommands($commandTimeout);
                // Uma ligação TCP aberta anuncia-a o servidor ao fechar o socket; as outras, aqui.
                foreach ($store->expireStaleDevices($deviceIdleTimeout) as $imei) {
                    $device = $store->device($imei);
                    if (($device['transport'] ?? '') !== 'tcp' || !$hubServer->isConnected($imei)) {
                        $connections->announce($imei, $device, false);
                    }
                }
            }
        );

        $loop->addPeriodicTimer(
            self::INTERVAL_SECONDS,
            static function () use ($hubServer, $deviceIdleTimeout): void {
                $hubServer->expireIdleConnections($deviceIdleTimeout);
            }
        );

        $timeZones = null;
        $loop->addPeriodicTimer(
            self::TIME_ZONE_INTERVAL_SECONDS,
            static function () use (&$timeZones, $services, $store, $hubServer): void {
                try {
                    $timeZones ??= new NamedTimeZoneFollower(
                        new DeviceService($store, $services->whitelist, $hubServer, $services->dataAccess),
                        $services->dataAccess->configurationLifecycle,
                        static fn (): array => $store->onlineDeviceImeis(),
                    );
                    foreach ($timeZones->tick() as $imei) {
                        Logger::channel('hub')->info("Time zone offset rewritten IMEI={$imei}");
                    }
                } catch (\Throwable $error) {
                    Logger::channel('hub')->error("Time zone follow failed: {$error->getMessage()}");
                }
            }
        );
    }
}
