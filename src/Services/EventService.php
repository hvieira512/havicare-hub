<?php

namespace App\Services;

use App\Domain\EventNormalizer;
use App\Domain\FeaturePayloadFormatter;
use App\Registry\DeviceCapabilities;
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

    public function latestDeviceFeatureEvent(?WatchServer $watchServer, string $imei, string $feature): ?array
    {
        if ($feature === '') {
            return null;
        }

        $candidate = null;

        if ($watchServer !== null) {
            $recent = $watchServer->getRecentEvents(250, null);
            $candidate = $this->pickLatestFeatureEventFromList($recent, $imei, $feature);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        if ($this->eventsRepo !== null) {
            // Primary path: exact feature match in storage.
            $direct = $this->eventsRepo->latestForImeiAndFeature($imei, $feature);
            if ($direct !== null) {
                $normalized = $this->normalizeForFeature($feature, $direct);
                if ($normalized !== []) {
                    $direct['featureNormalizedData'] = $normalized;
                    return $direct;
                }
            }

            // Fallback path: mixed native payloads that may carry multiple feature values.
            $recent = $this->eventsRepo->findRecent(250, null, $imei);
            $candidate = $this->pickLatestFeatureEventFromList($recent, $imei, $feature);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public function waitLatestDeviceFeatureEvent(
        ?WatchServer $watchServer,
        string $imei,
        string $feature,
        int $afterReceivedAtMs,
        int $timeoutMs,
        int $pollIntervalMs = 250,
    ): ?array {
        $timeoutMs = max(0, min(60000, $timeoutMs));
        $pollIntervalMs = max(100, min(1000, $pollIntervalMs));
        $deadline = (int)round(microtime(true) * 1000) + $timeoutMs;

        do {
            $event = $this->latestDeviceFeatureEvent($watchServer, $imei, $feature);
            if ($event !== null) {
                $receivedAtMs = $this->eventReceivedAtMs($event);
                if ($receivedAtMs !== null && $receivedAtMs >= $afterReceivedAtMs) {
                    return $event;
                }
            }

            if ($timeoutMs === 0) {
                break;
            }

            usleep($pollIntervalMs * 1000);
        } while ((int)round(microtime(true) * 1000) <= $deadline);

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
        $model = trim((string)($body['model'] ?? ''));
        $feature = null;
        if ($model !== '') {
            $caps = DeviceCapabilities::forModel($model);
            if ($caps !== null) {
                $feature = $caps->featureForPassive($type);
            }
        }

        if ($imei === '' || $type === '') {
            throw new ServiceException('invalid_request', 'imei and type are required', 400);
        }

        $existsStmt = $pdo->prepare('SELECT 1 FROM devices WHERE imei = ? LIMIT 1');
        $existsStmt->execute([$imei]);
        if ($existsStmt->fetchColumn() === false) {
            throw new ServiceException('device_not_found', "Device '$imei' is not registered", 404);
        }

        $event = [
            'imei' => $imei,
            'nativeType' => $type,
            'feature' => $feature,
            'nativePayload' => $data,
            'receivedAt' => (int)round(microtime(true) * 1000),
        ];

        try {
            $eventId = $this->eventsRepo->insert($event);

            if ($watchServer !== null) {
                $watchServer->ingestEvent($event, $eventId);
            }
        } catch (\Throwable $e) {
            throw new ServiceException(
                'event_persist_failed',
                'Failed to persist simulated event',
                500,
                ['cause' => $e->getMessage()]
            );
        }

        $featurePayload = null;
        if (is_string($feature) && $feature !== '') {
            $normalized = EventNormalizer::normalize($feature, $type, $data);
            $featurePayload = FeaturePayloadFormatter::format($feature, [
                'id' => $eventId,
                'imei' => $imei,
                'feature' => $feature,
                'nativeType' => $type,
                'nativePayload' => $data,
                'featureNormalizedData' => $normalized,
                'receivedAt' => $event['receivedAt'],
            ]);
        }

        return [
            'data' => [
                'status' => 'simulated',
                'imei' => $imei,
                'type' => $type,
                'id' => $eventId,
                'feature' => $feature,
                'featurePayload' => $featurePayload,
            ],
        ];
    }

    private function isRedisAvailable(): bool
    {
        return $this->redis !== null && $this->redis->isAvailable();
    }

    private function pickLatestFeatureEventFromList(array $events, string $imei, string $feature): ?array
    {
        foreach ($events as $event) {
            if (($event['imei'] ?? null) !== $imei) {
                continue;
            }

            $normalized = $this->normalizeForFeature($feature, $event);
            if ($normalized === []) {
                continue;
            }

            $event['featureNormalizedData'] = $normalized;
            return $event;
        }

        return null;
    }

    private function normalizeForFeature(string $feature, array $event): array
    {
        if (($event['feature'] ?? null) === $feature) {
            $existing = $event['generalizedData'] ?? null;
            if (is_array($existing) && $existing !== []) {
                return $existing;
            }
        }

        $nativePayload = $event['nativeData'] ?? $event['nativePayload'] ?? $event['data'] ?? [];
        if (!is_array($nativePayload)) {
            return [];
        }

        $nativeType = (string)($event['nativeType'] ?? $event['type'] ?? '');
        $normalized = EventNormalizer::normalize($feature, $nativeType, $nativePayload);

        return is_array($normalized) ? $normalized : [];
    }

    private function eventReceivedAtMs(array $event): ?int
    {
        $value = $event['receivedAt'] ?? $event['timestamp'] ?? null;
        if ($value === null || !is_numeric((string)$value)) {
            return null;
        }

        $ts = (int)$value;
        if ($ts <= 0) {
            return null;
        }

        if ($ts < 1000000000000) {
            return $ts * 1000;
        }

        return $ts;
    }
}
