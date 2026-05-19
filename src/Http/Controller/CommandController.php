<?php

namespace App\Http\Controller;

use App\Redis\Client as RedisClient;
use App\Services\CommandService;
use App\Services\ServiceException;
use App\WebSocket\WatchServer;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class CommandController extends Controller
{
    private CommandService $commandService;

    public function __construct(
        ?WatchServer $watchServer = null,
        ?\PDO $pdo = null,
        ?RedisClient $redis = null,
        ?string $wsServerUrl = null,
        ?CommandService $commandService = null,
    ) {
        parent::__construct($watchServer, $pdo, $redis, $wsServerUrl);
        if ($commandService === null) {
            throw new \InvalidArgumentException('CommandService is required');
        }
        $this->commandService = $commandService;
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
}
