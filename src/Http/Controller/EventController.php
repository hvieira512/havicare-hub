<?php

namespace App\Http\Controller;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use App\Domain\EventNormalizer;

class EventController extends Controller
{
    public function recentEvents(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $limit = $this->parseLimit($params['limit'] ?? null);
        $afterId = isset($params['after']) ? (int)$params['after'] : null;

        $events = $this->recentEventsFromServer($limit, $afterId);

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
        $data = $this->deviceData($imei);
        if ($data === null) {
            return $this->errorResponse('no_data', 'No data available for this device', 404);
        }

        return $this->jsonResponse(['data' => $this->eventResource($data)]);
    }

    private function recentEventsFromServer(int $limit = 50, ?int $afterId = null): array
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->getRecentEvents($limit, $afterId);
        }
        if ($this->eventsRepo !== null) {
            return $this->eventsRepo->findRecent($limit, $afterId);
        }
        return [];
    }

    private function eventResource(array $event): array
    {
        $nativePayload = $event['nativePayload'] ?? $event['data'] ?? [];
        $nativeType = $event['nativeType'] ?? $event['type'] ?? null;
        $feature = $event['feature'] ?? null;

        return [
            'id' => $event['id'] ?? null,
            'imei' => $event['imei'] ?? null,
            'model' => $event['model'] ?? null,
            'direction' => 'watch_to_server',
            'feature' => $feature,
            'nativeType' => $nativeType,
            'receivedAt' => $this->toSeconds($event['receivedAt'] ?? $event['timestamp'] ?? null),
            'nativePayload' => $nativePayload,
            'normalized' => EventNormalizer::normalize($feature, $nativeType, $nativePayload),
        ];
    }
}
