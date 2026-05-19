<?php

namespace App\Services;

use App\Redis\Client as RedisClient;
use App\Registry\DeviceCapabilities;
use App\Registry\Whitelist;
use App\WebSocket\WatchServer;

class SystemService
{
    private ?\PDO $pdo;
    private ?RedisClient $redis;
    private Whitelist $whitelist;
    private ?WatchServer $watchServer;

    public function __construct(?\PDO $pdo, ?RedisClient $redis, Whitelist $whitelist, ?WatchServer $watchServer)
    {
        $this->pdo = $pdo;
        $this->redis = $redis;
        $this->whitelist = $whitelist;
        $this->watchServer = $watchServer;
    }

    public function healthPayload(): array
    {
        $dbOk = $this->pdo !== null;
        $redisOk = $this->redis !== null && $this->redis->isAvailable();

        return [
            'status' => ($dbOk ? 'ok' : 'degraded'),
            'services' => [
                'mysql' => $dbOk,
                'redis' => $redisOk,
                'watchServerAttached' => $this->watchServer !== null,
            ],
            'onlineDevices' => $this->watchServer !== null ? $this->watchServer->onlineDeviceCount() : 0,
            'time' => time(),
        ];
    }

    public function metricsPayload(): array
    {
        return [
            'onlineDevices' => $this->watchServer !== null ? $this->watchServer->onlineDeviceCount() : 0,
            'knownModels' => DeviceCapabilities::allModels(),
            'totalDevices' => count($this->whitelist->all()),
            'time' => time(),
        ];
    }
}
