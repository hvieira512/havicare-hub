<?php

namespace App\Runtime;

use App\Redis\Client as RedisClient;
use App\Registry\Whitelist;
use App\Repository\SupplierRepository;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Repository\ModelRepository;
use App\Protocol\AdapterRegistry;
use App\Services\CommandService;
use App\Services\DeviceService;
use App\Services\EventService;
use App\Services\SystemService;
use App\WebSocket\WatchServer;

class ServiceComposer
{
    public static function forApi(
        ?\PDO $pdo,
        ?RedisClient $redis,
        ?WatchServer $watchServer,
        ?string $wsServerUrl = null,
    ): array {
        $whitelist = $watchServer?->getWhitelist() ?? new Whitelist(pdo: $pdo);

        $deviceRepo = $pdo ? new DeviceRepository($pdo) : null;
        $eventRepo = $pdo ? new EventRepository($pdo) : null;
        $modelRepo = $pdo ? new ModelRepository($pdo) : null;
        $supplierRepo = $pdo ? new SupplierRepository($pdo) : null;

        $onlineResolver = static function (string $imei) use ($watchServer, $redis): bool {
            if ($watchServer !== null) {
                return $watchServer->isOnline($imei);
            }
            if ($redis !== null) {
                return $redis->deviceGetNode($imei) !== null;
            }
            return false;
        };

        $warmup = static function (string $imei) use ($watchServer, $eventRepo): void {
            if ($watchServer !== null) {
                $watchServer->getDeviceData($imei);
                return;
            }
            if ($eventRepo !== null) {
                $eventRepo->latestForImei($imei);
            }
        };

        $deviceService = new DeviceService($whitelist, $pdo, $deviceRepo, $modelRepo, $onlineResolver, $warmup);
        $commandService = new CommandService($whitelist, $watchServer, $redis, $onlineResolver);
        $eventService = new EventService($eventRepo, $redis);
        $systemService = new SystemService($pdo, $redis, $whitelist, $watchServer);
        $registry = new AdapterRegistry();
        $envWsServerUrl = getenv('WS_SERVER_URL');
        $resolvedWsServerUrl = $wsServerUrl
            ?: (($envWsServerUrl !== false && $envWsServerUrl !== '')
                ? $envWsServerUrl
                : 'ws://127.0.0.1:8080');
        $httpContext = new HttpRuntimeContext(
            watchServer: $watchServer,
            pdo: $pdo,
            redis: $redis,
            wsServerUrl: $resolvedWsServerUrl,
            whitelist: $whitelist,
            deviceRepo: $deviceRepo,
            eventRepo: $eventRepo,
            modelRepo: $modelRepo,
            supplierRepo: $supplierRepo,
            supportedProtocols: $registry->getProtocols(),
        );

        return [
            'deviceService' => $deviceService,
            'commandService' => $commandService,
            'eventService' => $eventService,
            'systemService' => $systemService,
            'httpContext' => $httpContext,
        ];
    }

    public static function forWatchServer(
        ?\PDO $pdo,
        ?RedisClient $redis,
        ?WatchServer $watchServer = null,
    ): array {
        $whitelist = $watchServer?->getWhitelist() ?? new Whitelist(pdo: $pdo);
        $eventRepo = $pdo ? new EventRepository($pdo) : null;
        $eventService = new EventService($eventRepo, $redis);

        $onlineResolver = static function (string $imei) use ($watchServer, $redis): bool {
            if ($watchServer !== null) {
                return $watchServer->isOnline($imei);
            }
            if ($redis !== null) {
                return $redis->deviceGetNode($imei) !== null;
            }
            return false;
        };

        $commandService = new CommandService($whitelist, $watchServer, $redis, $onlineResolver);

        return [
            'eventService' => $eventService,
            'commandService' => $commandService,
        ];
    }
}
