<?php

namespace App\Http\Controller;

use App\Redis\Client as RedisClient;
use App\Runtime\HttpRuntimeContext;
use App\Services\DeviceService;
use App\Services\ServiceException;
use App\WebSocket\WatchServer;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class DeviceController extends Controller
{
    private DeviceService $deviceService;

    public function __construct(
        ?WatchServer $watchServer = null,
        ?\PDO $pdo = null,
        ?RedisClient $redis = null,
        ?string $wsServerUrl = null,
        ?HttpRuntimeContext $runtimeContext = null,
        ?DeviceService $deviceService = null,
    ) {
        parent::__construct($watchServer, $pdo, $redis, $wsServerUrl, $runtimeContext);
        if ($deviceService === null) {
            throw new \InvalidArgumentException('DeviceService is required');
        }
        $this->deviceService = $deviceService;
    }

    public function listDevices(ServerRequestInterface $request): Response
    {
        return $this->jsonResponse($this->deviceService->listDevices($request->getQueryParams()));
    }

    public function getDevice(string $imei): Response
    {
        try {
            return $this->jsonResponse($this->deviceService->getDevice($imei));
        } catch (ServiceException $e) {
            return $this->errorResponse($e->codeName(), $e->getMessage(), $e->status(), $e->details());
        }
    }

    public function createDevice(ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        try {
            $result = $this->deviceService->createDevice($body);
            return $this->jsonResponse($result['payload'], (int)($result['status'] ?? 201));
        } catch (ServiceException $e) {
            return $this->errorResponse($e->codeName(), $e->getMessage(), $e->status(), $e->details());
        }
    }

    public function updateDevice(string $imei, ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        try {
            return $this->jsonResponse($this->deviceService->updateDevice($imei, $body));
        } catch (ServiceException $e) {
            return $this->errorResponse($e->codeName(), $e->getMessage(), $e->status(), $e->details());
        }
    }

    public function deleteDevice(string $imei): Response
    {
        try {
            return $this->jsonResponse($this->deviceService->deleteDevice($imei));
        } catch (ServiceException $e) {
            return $this->errorResponse($e->codeName(), $e->getMessage(), $e->status(), $e->details());
        }
    }
}
