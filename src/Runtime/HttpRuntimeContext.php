<?php

namespace App\Runtime;

use App\Redis\Client as RedisClient;
use App\Registry\Whitelist;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Repository\ModelRepository;
use App\Repository\SupplierRepository;
use App\WebSocket\WatchServer;

final class HttpRuntimeContext
{
    public function __construct(
        public readonly ?WatchServer $watchServer,
        public readonly ?\PDO $pdo,
        public readonly ?RedisClient $redis,
        public readonly string $wsServerUrl,
        public readonly Whitelist $whitelist,
        public readonly ?DeviceRepository $deviceRepo,
        public readonly ?EventRepository $eventRepo,
        public readonly ?ModelRepository $modelRepo,
        public readonly ?SupplierRepository $supplierRepo,
        /** @var string[] */
        public readonly array $supportedProtocols,
    ) {
    }
}
