<?php

namespace App\Http\Controller;

use App\Domain\EventNormalizer;
use App\Domain\FeaturePayloadFormatter;
use App\Redis\Client as RedisClient;
use App\Runtime\HttpRuntimeContext;
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
        ?HttpRuntimeContext $runtimeContext = null,
        ?EventService $eventService = null,
    ) {
        parent::__construct($watchServer, $pdo, $redis, $wsServerUrl, $runtimeContext);
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

    public function latestDeviceFeaturePayload(string $imei, string $feature): Response
    {
        $feature = trim($feature);
        if ($feature === '') {
            return $this->errorResponse('invalid_feature', 'Feature is required', 400);
        }

        $data = $this->eventService->latestDeviceFeatureEvent($this->watchServer, $imei, $feature);
        if ($data === null) {
            return $this->errorResponse('no_data', "No data available for feature '$feature' on this device", 404);
        }

        return $this->jsonResponse([
            'data' => FeaturePayloadFormatter::format($feature, $data),
        ]);
    }

    private function eventResource(array $event): array
    {
        $nativePayload = $event['nativeData'] ?? $event['nativePayload'] ?? $event['data'] ?? [];
        $nativeType = $event['nativeType'] ?? $event['type'] ?? null;
        $feature = $event['feature'] ?? null;
        $protocol = isset($event['protocol']) ? (string)$event['protocol'] : null;
        $normalized = $event['generalizedData'] ?? EventNormalizer::normalize($feature, $nativeType, $nativePayload, $protocol);
        $resource = [
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

        if (is_string($feature) && $feature !== '') {
            $resource['featurePayload'] = FeaturePayloadFormatter::format($feature, [
                'id' => $event['id'] ?? null,
                'imei' => $event['imei'] ?? null,
                'feature' => $feature,
                'nativeType' => $nativeType,
                'protocol' => $protocol,
                'nativePayload' => is_array($nativePayload) ? $nativePayload : [],
                'featureNormalizedData' => is_array($normalized) ? $normalized : [],
                'receivedAt' => $event['receivedAt'] ?? $event['timestamp'] ?? null,
            ]);
        }

        return $resource;
    }
}
