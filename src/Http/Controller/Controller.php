<?php

namespace App\Http\Controller;

use React\Http\Message\Response;
use App\WebSocket\WatchServer;
use App\Registry\Whitelist;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Repository\ModelRepository;
use App\Repository\SupplierRepository;
use App\Redis\Client as RedisClient;
use App\Protocol\AdapterRegistry;

abstract class Controller
{
    protected ?WatchServer $watchServer;
    protected ?Whitelist $whitelist = null;
    protected ?\PDO $pdo;
    protected ?DeviceRepository $deviceRepo;
    protected ?EventRepository $eventsRepo;
    protected ?ModelRepository $modelRepo;
    protected ?SupplierRepository $supplierRepo;
    protected ?RedisClient $redis;
    protected string $wsServerUrl;

    public function __construct(
        ?WatchServer $watchServer = null,
        ?\PDO $pdo = null,
        ?RedisClient $redis = null,
        ?string $wsServerUrl = null,
    ) {
        $this->watchServer = $watchServer;
        $this->pdo = $pdo;
        $this->deviceRepo = $pdo ? new DeviceRepository($pdo) : null;
        $this->eventsRepo = $pdo ? new EventRepository($pdo) : null;
        $this->modelRepo = $pdo ? new ModelRepository($pdo) : null;
        $this->supplierRepo = $pdo ? new SupplierRepository($pdo) : null;
        $this->redis = $redis;

        $envWsServerUrl = getenv('WS_SERVER_URL');
        $this->wsServerUrl = $wsServerUrl
            ?: (($envWsServerUrl !== false && $envWsServerUrl !== '')
                ? $envWsServerUrl
                : 'ws://127.0.0.1:8080');
    }

    protected function whitelist(): Whitelist
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->getWhitelist();
        }
        if ($this->whitelist === null) {
            $this->whitelist = new Whitelist(pdo: $this->pdo);
        }
        return $this->whitelist;
    }

    protected function deviceData(string $imei): ?array
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->getDeviceData($imei);
        }
        if ($this->eventsRepo !== null) {
            return $this->eventsRepo->latestForImei($imei);
        }
        return null;
    }

    protected function deviceIsOnline(string $imei): bool
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->isOnline($imei);
        }
        if ($this->redis !== null) {
            return $this->redis->deviceGetNode($imei) !== null;
        }
        return false;
    }

    protected function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function errorResponse(string $code, string $message, int $status, array $details = []): Response
    {
        $payload = ['error' => ['code' => $code, 'message' => $message]];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }
        return $this->jsonResponse($payload, $status);
    }

    protected function parsePage(mixed $value): int
    {
        $page = $value !== null ? (int)$value : 1;
        return max(1, $page);
    }

    protected function parseLimit(mixed $value): int
    {
        $limit = $value !== null ? (int)$value : 50;
        return max(1, min(200, $limit));
    }

    protected function parseNullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        $lower = strtolower((string)$value);
        if ($lower === '1' || $lower === 'true' || $lower === 'yes') {
            return true;
        }
        if ($lower === '0' || $lower === 'false' || $lower === 'no') {
            return false;
        }
        return null;
    }

    protected function paginationResource(int $page, int $limit, int $total): array
    {
        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
        ];
    }

    protected function supportedProtocols(): array
    {
        $registry = new AdapterRegistry();
        return $registry->getProtocols();
    }

    protected function isSupportedProtocol(string $protocol): bool
    {
        return in_array($protocol, $this->supportedProtocols(), true);
    }

    protected function toSeconds(mixed $timestamp): ?int
    {
        if ($timestamp === null) {
            return null;
        }
        $value = (int)$timestamp;
        if ($value > 1000000000000) {
            return (int)round($value / 1000);
        }
        return $value;
    }
}
