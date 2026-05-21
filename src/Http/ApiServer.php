<?php

namespace App\Http;

use App\Http\Controller\CommandController;
use App\Http\Controller\DeviceController;
use App\Http\Controller\EventController;
use App\Http\Controller\ModelController;
use App\Http\Controller\SupplierController;
use App\Http\Controller\SystemController;
use App\Log\Logger;
use App\Redis\Client as RedisClient;
use App\Registry\DeviceCapabilities;
use App\Runtime\ServiceComposer;
use App\Services\CommandService;
use App\Services\DeviceService;
use App\Services\EventService;
use App\Services\SystemService;
use App\WebSocket\WatchServer;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

class ApiServer
{
    private DeviceController $deviceController;
    private SupplierController $supplierController;
    private ModelController $modelController;
    private EventController $eventController;
    private CommandController $commandController;
    private SystemController $systemController;
    private HttpServer $http;
    private SocketServer $socket;

    public function __construct(
        ?WatchServer $watchServer,
        LoopInterface $loop,
        int $port,
        string $host = '0.0.0.0',
        ?\PDO $pdo = null,
        ?RedisClient $redis = null,
        ?string $wsServerUrl = null,
        ?DeviceService $deviceService = null,
        ?CommandService $commandService = null,
        ?EventService $eventService = null,
        ?SystemService $systemService = null,
    ) {
        DeviceCapabilities::setDatabasePdo($pdo);
        DeviceCapabilities::setCacheTtl((int)(getenv('MODEL_CACHE_TTL_SECONDS') ?: 5));

        $envWsServerUrl = getenv('WS_SERVER_URL');
        $wsServerUrl = $wsServerUrl
            ?: (($envWsServerUrl !== false && $envWsServerUrl !== '')
                ? $envWsServerUrl
                : 'ws://127.0.0.1:8080');

        if ($deviceService === null || $commandService === null || $eventService === null || $systemService === null) {
            $services = ServiceComposer::forApi($pdo, $redis, $watchServer);
            $deviceService = $deviceService ?? $services['deviceService'];
            $commandService = $commandService ?? $services['commandService'];
            $eventService = $eventService ?? $services['eventService'];
            $systemService = $systemService ?? $services['systemService'];
        }

        $this->deviceController = new DeviceController($watchServer, $pdo, $redis, $wsServerUrl, $deviceService);
        $this->supplierController = new SupplierController($watchServer, $pdo, $redis, $wsServerUrl);
        $this->modelController = new ModelController($watchServer, $pdo, $redis, $wsServerUrl);
        $this->eventController = new EventController($watchServer, $pdo, $redis, $wsServerUrl, $eventService);
        $this->commandController = new CommandController($watchServer, $pdo, $redis, $wsServerUrl, $commandService, $eventService);
        $this->systemController = new SystemController($watchServer, $pdo, $redis, $wsServerUrl, $systemService, $eventService);

        $this->http = new HttpServer($loop, \Closure::fromCallable([$this, 'handleRequest']));
        $this->socket = new SocketServer("$host:$port", [], $loop);
        $this->http->listen($this->socket);

        Logger::channel('api')->info("HTTP API at http://$host:$port");
        Logger::channel('api')->info("WS server URL: $wsServerUrl");
        if ($watchServer === null) {
            Logger::channel('api')->info('Separate mode: commands are sent via Redis Stream');
        }
    }

    private function handleRequest(ServerRequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = rtrim($request->getUri()->getPath(), '/');

        if ($method === 'OPTIONS') {
            return new Response(204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        try {
            return match (true) {
                $method === 'GET' && $path === '/devices' => $this->deviceController->listDevices($request),
                $method === 'POST' && $path === '/devices' => $this->deviceController->createDevice($request),
                $method === 'GET' && preg_match('#^/devices/([^/]+)$#', $path, $m) === 1 => $this->deviceController->getDevice($m[1]),
                $method === 'PUT' && preg_match('#^/devices/([^/]+)$#', $path, $m) === 1 => $this->deviceController->updateDevice($m[1], $request),
                $method === 'DELETE' && preg_match('#^/devices/([^/]+)$#', $path, $m) === 1 => $this->deviceController->deleteDevice($m[1]),

                $method === 'GET' && $path === '/suppliers' => $this->supplierController->listSuppliers($request),
                $method === 'POST' && $path === '/suppliers' => $this->supplierController->createSupplier($request),
                $method === 'GET' && preg_match('#^/suppliers/(\d+)$#', $path, $m) === 1 => $this->supplierController->getSupplier((int)$m[1]),
                $method === 'PUT' && preg_match('#^/suppliers/(\d+)$#', $path, $m) === 1 => $this->supplierController->updateSupplier((int)$m[1], $request),
                $method === 'DELETE' && preg_match('#^/suppliers/(\d+)$#', $path, $m) === 1 => $this->supplierController->deleteSupplier((int)$m[1]),

                $method === 'GET' && $path === '/models' => $this->modelController->listModels($request),
                $method === 'POST' && $path === '/models' => $this->modelController->createModel($request),
                $method === 'GET' && preg_match('#^/models/([^/]+)$#', $path, $m) === 1 => $this->modelController->getModel($m[1]),
                $method === 'PUT' && preg_match('#^/models/([^/]+)$#', $path, $m) === 1 => $this->modelController->updateModel($m[1], $request),
                $method === 'DELETE' && preg_match('#^/models/([^/]+)$#', $path, $m) === 1 => $this->modelController->deleteModel($m[1]),
                $method === 'GET' && preg_match('#^/models/([^/]+)/feature-mappings$#', $path, $m) === 1 => $this->modelController->listFeatureMappings($m[1]),
                $method === 'PUT' && preg_match('#^/models/([^/]+)/feature-mappings$#', $path, $m) === 1 => $this->modelController->replaceFeatureMappings($m[1], $request),
                $method === 'POST' && preg_match('#^/models/([^/]+)/feature-mappings$#', $path, $m) === 1 => $this->modelController->upsertFeatureMapping($m[1], $request),
                $method === 'DELETE' && preg_match('#^/models/([^/]+)/feature-mappings/([^/]+)$#', $path, $m) === 1 => $this->modelController->deleteFeatureMapping($m[1], $m[2]),

                $method === 'GET' && $path === '/events/recent' => $this->eventController->recentEvents($request),
                $method === 'GET' && preg_match('#^/devices/([^/]+)/events/latest$#', $path, $m) === 1 => $this->eventController->latestDeviceEvent($m[1]),
                $method === 'GET' && preg_match('#^/devices/([^/]+)/features/([^/]+)/latest$#', $path, $m) === 1 => $this->eventController->latestDeviceFeaturePayload($m[1], $m[2]),
                $method === 'GET' && preg_match('#^/devices/([^/]+)/features$#', $path, $m) === 1 => $this->commandController->deviceFeatures($m[1]),
                $method === 'POST' && preg_match('#^/devices/([^/]+)/command$#', $path, $m) === 1 => $this->commandController->sendCommand($m[1], $request),
                $method === 'POST' && preg_match('#^/devices/([^/]+)/features/([^/]+)/measure$#', $path, $m) === 1 => $this->commandController->measureFeature($m[1], $m[2], $request),
                $method === 'POST' && preg_match('#^/devices/([^/]+)/features/([^/]+)/command$#', $path, $m) === 1 => $this->commandController->sendFeatureCommand($m[1], $m[2], $request),

                $method === 'GET' && $path === '/health' => $this->systemController->healthCheck(),
                $method === 'GET' && $path === '/metrics' => $this->systemController->metricsEndpoint(),
                $method === 'GET' && $path === '/demo' => $this->systemController->demoPage(),
                $method === 'POST' && $path === '/demo/simulate' => $this->systemController->simulateDeviceEvent($request),
                $method === 'POST' && $path === '/demo/listener' => $this->systemController->startDemoListener($request),
                $method === 'GET' && $path === '/demo/listeners' => $this->systemController->demoListeners(),
                $method === 'DELETE' && preg_match('#^/demo/listener/([^/]+)$#', $path, $m) === 1 => $this->systemController->stopDemoListener($m[1]),
                $method === 'GET' && $path === '/openapi.json' => $this->systemController->openApiSpec(),
                $method === 'GET' && $path === '/docs' => $this->systemController->swaggerUi(),
                default => $this->jsonResponse(['error' => ['code' => 'not_found', 'message' => 'Endpoint not found']], 404),
            };
        } catch (\Throwable $e) {
            Logger::channel('api')->error(sprintf(
                'Unhandled exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            return $this->jsonResponse(['error' => ['code' => 'internal_error', 'message' => 'Internal server error']], 500);
        }
    }

    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
