<?php

namespace App\Http\Controller;

use App\Domain\FeaturePayloadFormatter;
use App\Redis\Client as RedisClient;
use App\Services\CommandService;
use App\Services\EventService;
use App\Services\ServiceException;
use App\WebSocket\WatchServer;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class CommandController extends Controller
{
    private CommandService $commandService;
    private ?EventService $eventService;

    public function __construct(
        ?WatchServer $watchServer = null,
        ?\PDO $pdo = null,
        ?RedisClient $redis = null,
        ?string $wsServerUrl = null,
        ?CommandService $commandService = null,
        ?EventService $eventService = null,
    ) {
        parent::__construct($watchServer, $pdo, $redis, $wsServerUrl);
        if ($commandService === null) {
            throw new \InvalidArgumentException('CommandService is required');
        }
        $this->commandService = $commandService;
        $this->eventService = $eventService;
    }

    public function sendCommand(string $imei, ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        try {
            return $this->jsonResponse($this->commandService->sendCommand($imei, $body));
        } catch (ServiceException $e) {
            return $this->errorResponse($e->codeName(), $e->getMessage(), $e->status(), $e->details());
        }
    }

    public function deviceFeatures(string $imei): Response
    {
        try {
            return $this->jsonResponse($this->commandService->deviceFeatures($imei));
        } catch (ServiceException $e) {
            return $this->errorResponse($e->codeName(), $e->getMessage(), $e->status(), $e->details());
        }
    }

    public function sendFeatureCommand(string $imei, string $feature, ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true);
        $body = is_array($body) ? $body : [];

        try {
            return $this->jsonResponse($this->commandService->sendFeatureCommand($imei, $feature, $body));
        } catch (ServiceException $e) {
            return $this->errorResponse($e->codeName(), $e->getMessage(), $e->status(), $e->details());
        }
    }

    public function measureFeature(string $imei, string $feature, ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true);
        $body = is_array($body) ? $body : [];

        try {
            $response = $this->commandService->measureFeature($imei, $feature, $body);
            $query = $request->getQueryParams();

            $wait = $this->parseNullableBool($query['wait'] ?? ($body['wait'] ?? null)) ?? false;
            $timeoutMs = (int)($query['timeoutMs'] ?? ($body['timeoutMs'] ?? 0));
            if ($timeoutMs <= 0) {
                $timeoutMs = 15000;
            }
            $timeoutMs = max(0, min(60000, $timeoutMs));

            if (!$wait) {
                return $this->jsonResponse($response);
            }

            if ($this->eventService === null) {
                return $this->errorResponse('feature_wait_unavailable', 'Feature wait mode is unavailable', 503);
            }

            $requestedAt = (int)($response['measurement']['requestedAt'] ?? (int)round(microtime(true) * 1000));
            $event = $this->eventService->waitLatestDeviceFeatureEvent(
                $this->watchServer,
                $imei,
                $feature,
                $requestedAt,
                $timeoutMs
            );

            if ($event !== null) {
                $response['wait'] = [
                    'status' => 'received',
                    'timeoutMs' => $timeoutMs,
                ];
                $response['latest'] = FeaturePayloadFormatter::format($feature, $event);
                return $this->jsonResponse($response);
            }

            $response['wait'] = [
                'status' => 'timeout',
                'timeoutMs' => $timeoutMs,
            ];
            return $this->jsonResponse($response, 202);
        } catch (ServiceException $e) {
            return $this->errorResponse($e->codeName(), $e->getMessage(), $e->status(), $e->details());
        }
    }
}
