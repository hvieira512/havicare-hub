<?php

namespace App\Services;

use App\Repository\EventRepository;
use App\Redis\Client as RedisClient;
use App\WebSocket\WatchServer;

class EventService
{
    private ?EventRepository $eventsRepo;
    private ?RedisClient $redis;

    public function __construct(?EventRepository $eventsRepo, ?RedisClient $redis)
    {
        $this->eventsRepo = $eventsRepo;
        $this->redis = $redis;
    }

    public function recentEvents(?WatchServer $watchServer, int $limit, ?int $afterId = null): array
    {
        if ($watchServer !== null) {
            return $watchServer->getRecentEvents($limit, $afterId);
        }
        if ($this->eventsRepo !== null) {
            return $this->eventsRepo->findRecent($limit, $afterId);
        }

        return [];
    }

    public function latestDeviceEvent(?WatchServer $watchServer, string $imei): ?array
    {
        if ($watchServer !== null) {
            return $watchServer->getDeviceData($imei);
        }
        if ($this->eventsRepo !== null) {
            return $this->eventsRepo->latestForImei($imei);
        }

        return null;
    }

    public function persistWatchIngressEvent(array $event, int &$nextEventId): array
    {
        if ($this->isRedisAvailable()) {
            $streamId = $this->redis->eventPush($event);
            $parts = explode('-', $streamId);
            $event['id'] = (int)$parts[0];
            return $event;
        }

        if ($this->eventsRepo !== null) {
            $event['id'] = $this->eventsRepo->insert($event);
            return $event;
        }

        $event['id'] = $nextEventId++;
        return $event;
    }

    public function ingestInMemory(array $event, array &$deviceData, array &$eventHistory, int $maxHistory = 200): void
    {
        $imei = (string)$event['imei'];
        $deviceData[$imei] = $event;
        $eventHistory[] = $event;
        if (count($eventHistory) > $maxHistory) {
            array_shift($eventHistory);
        }
    }

    public function simulateDeviceEvent(
        ?\PDO $pdo,
        ?WatchServer $watchServer,
        array $body,
    ): array {
        if ($pdo === null) {
            throw new ServiceException('mysql_unavailable', 'MySQL is not available', 503);
        }
        if ($this->eventsRepo === null) {
            throw new ServiceException('mysql_unavailable', 'Event repository is not available', 503);
        }

        $imei = trim((string)($body['imei'] ?? ''));
        $type = trim((string)($body['type'] ?? ''));
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if ($imei === '' || $type === '') {
            throw new ServiceException('invalid_request', 'imei and type are required', 400);
        }

        $event = [
            'imei' => $imei,
            'nativeType' => $type,
            'feature' => null,
            'nativePayload' => $data,
            'receivedAt' => (int)round(microtime(true) * 1000),
        ];

        $eventId = $this->eventsRepo->insert($event);

        if ($this->isRedisAvailable()) {
            $this->redis->eventPush($event);
        }

        if ($watchServer !== null) {
            $watchServer->ingestEvent($event, $eventId);
        }

        return [
            'data' => [
                'status' => 'simulated',
                'imei' => $imei,
                'type' => $type,
                'id' => $eventId,
            ],
        ];
    }

    private function isRedisAvailable(): bool
    {
        return $this->redis !== null && $this->redis->isAvailable();
    }
}
