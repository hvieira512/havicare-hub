<?php

namespace App\Http\Controller;

use App\Domain\EventNormalizer;
use App\Redis\Client as RedisClient;
use App\Services\EventService;
use App\WebSocket\WatchServer;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class EventController extends Controller
{
    private EventService $eventService;

    public function __construct(
        ?WatchServer $watchServer = null,
        ?\PDO $pdo = null,
        ?RedisClient $redis = null,
        ?string $wsServerUrl = null,
        ?EventService $eventService = null,
    ) {
        parent::__construct($watchServer, $pdo, $redis, $wsServerUrl);
        if ($eventService === null) {
            throw new \InvalidArgumentException('EventService is required');
        }
        $this->eventService = $eventService;
    }

    public function recentEvents(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $limit = $this->parseLimit($params['limit'] ?? null);
        $afterId = isset($params['after']) ? (int)$params['after'] : null;

        $events = $this->eventService->recentEvents($this->watchServer, $limit, $afterId);

        return $this->jsonResponse([
            'data' => array_map(fn(array $event): array => $this->eventResource($event), $events),
            'meta' => [
                'count' => count($events),
                'limit' => $limit,
            ],
        ]);
    }

    public function latestDeviceEvent(string $imei): Response
    {
        $data = $this->eventService->latestDeviceEvent($this->watchServer, $imei);
        if ($data === null) {
            return $this->errorResponse('no_data', 'No data available for this device', 404);
        }

        return $this->jsonResponse(['data' => $this->eventResource($data)]);
    }

    private function eventResource(array $event): array
    {
        $nativePayload = $event['nativeData'] ?? $event['nativePayload'] ?? $event['data'] ?? [];
        $nativeType = $event['nativeType'] ?? $event['type'] ?? null;
        $feature = $event['feature'] ?? null;
        $normalized = $event['generalizedData'] ?? EventNormalizer::normalize($feature, $nativeType, $nativePayload);

        return [
            'id' => $event['id'] ?? null,
            'imei' => $event['imei'] ?? null,
            'model' => $event['model'] ?? null,
            'direction' => 'watch_to_server',
            'feature' => $feature,
            'nativeType' => $nativeType,
            'receivedAt' => $this->toSeconds($event['receivedAt'] ?? $event['timestamp'] ?? null),
            'nativePayload' => $nativePayload,
            'normalized' => is_array($normalized) ? $normalized : [],
        ];
    }
}
