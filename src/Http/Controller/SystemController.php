<?php

namespace App\Http\Controller;

use App\Http\OpenApiSpec;
use App\Redis\Client as RedisClient;
use App\Runtime\HttpRuntimeContext;
use App\Services\EventService;
use App\Services\ServiceException;
use App\Services\SystemService;
use App\WebSocket\WatchServer;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class SystemController extends Controller
{
    private const REDIS_PREFIX = 'demo_listener:';
    private SystemService $systemService;
    private EventService $eventService;

    public function __construct(
        ?WatchServer $watchServer = null,
        ?\PDO $pdo = null,
        ?RedisClient $redis = null,
        ?string $wsServerUrl = null,
        ?HttpRuntimeContext $runtimeContext = null,
        ?SystemService $systemService = null,
        ?EventService $eventService = null,
    ) {
        parent::__construct($watchServer, $pdo, $redis, $wsServerUrl, $runtimeContext);
        $this->systemService = $systemService ?? new SystemService($this->pdo, $this->redis, $this->whitelist(), $this->watchServer);
        $this->eventService = $eventService ?? new EventService($this->eventsRepo, $this->redis);
    }

    public function healthCheck(): Response
    {
        return $this->jsonResponse($this->systemService->healthPayload());
    }

    public function metricsEndpoint(): Response
    {
        return $this->jsonResponse($this->systemService->metricsPayload());
    }

    public function simulateDeviceEvent(ServerRequestInterface $request): Response
    {
        if (!$this->demoApiEnabled()) {
            return $this->errorResponse('not_found', 'Endpoint not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        try {
            $payload = $this->eventService->simulateDeviceEvent($this->pdo, $this->watchServer, $body);
            return $this->jsonResponse($payload, 201);
        } catch (ServiceException $e) {
            return $this->errorResponse($e->codeName(), $e->getMessage(), $e->status(), $e->details());
        } catch (\Throwable $e) {
            return $this->errorResponse('simulate_failed', 'Failed to simulate device event', 500, [
                'cause' => $e->getMessage(),
            ]);
        }
    }

    public function startDemoListener(ServerRequestInterface $request): Response
    {
        if (!$this->demoApiEnabled()) {
            return $this->errorResponse('not_found', 'Endpoint not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        $imei = trim((string)($body['imei'] ?? ''));
        if ($imei === '') {
            return $this->errorResponse('invalid_request', 'imei is required', 400);
        }

        if ($this->redis?->get(self::REDIS_PREFIX . $imei)) {
            return $this->errorResponse('already_listening', 'Already listening for this IMEI', 409);
        }

        $model = $this->whitelist()->getModel($imei) ?? 'WONLEX-PRO';
        $escapedImei = escapeshellarg($imei);
        $escapedModel = escapeshellarg($model);
        $wsUrl = $this->wsServerUrl;

        $logFile = __DIR__ . "/../../../var/demo-listener-{$imei}.log";

        $cmd = sprintf(
            'cd %s && nohup php simulator/simulate.php --server %s --model %s --imei %s --listen > %s 2>&1 & echo $!',
            escapeshellarg(__DIR__ . '/../../..'),
            escapeshellarg($wsUrl),
            $escapedModel,
            $escapedImei,
            escapeshellarg($logFile)
        );

        $output = [];
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0 || !isset($output[0]) || !is_numeric(trim($output[0]))) {
            return $this->errorResponse('start_failed', 'Failed to start demo listener', 500);
        }

        $pid = (int)trim($output[0]);

        if (!$this->processIsRunning($pid)) {
            return $this->errorResponse('start_failed', 'Process exited immediately after start', 500);
        }

        $listener = ['pid' => $pid, 'imei' => $imei, 'model' => $model, 'started_at' => time()];
        $this->redis?->set(self::REDIS_PREFIX . $imei, json_encode($listener));

        return $this->jsonResponse(['data' => $this->listenerResource($listener)], 201);
    }

    public function stopDemoListener(string $imei): Response
    {
        if (!$this->demoApiEnabled()) {
            return $this->errorResponse('not_found', 'Endpoint not found', 404);
        }

        $raw = $this->redis?->get(self::REDIS_PREFIX . $imei);
        if (!$raw) {
            return $this->errorResponse('not_found', 'No demo listener for this IMEI', 404);
        }

        $listener = json_decode($raw, true);
        if ($listener && ($listener['pid'] ?? 0) > 0) {
            exec('kill ' . (int)$listener['pid'] . ' 2>/dev/null');
            usleep(500000);
        }

        $this->redis?->del(self::REDIS_PREFIX . $imei);

        return $this->jsonResponse(['data' => $this->listenerResource($listener ?? [])]);
    }

    public function demoListeners(): Response
    {
        if (!$this->demoApiEnabled()) {
            return $this->errorResponse('not_found', 'Endpoint not found', 404);
        }

        $listeners = $this->loadListeners();

        return $this->jsonResponse([
            'data' => array_values(array_map(
                fn(array $l): array => $this->listenerResource($l),
                $listeners
            )),
        ]);
    }

    public function demoPage(): Response
    {
        $path = __DIR__ . '/../demo.html';
        if (!file_exists($path)) {
            return $this->errorResponse('not_found', 'Demo page not found', 404);
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($path));
    }

    public function openApiSpec(): Response
    {
        return $this->jsonResponse(OpenApiSpec::get());
    }

    public function swaggerUi(): Response
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>API Docs</title>'
            . '<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">'
            . '</head><body><div id="swagger-ui"></div>'
            . '<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>'
            . '<script>window.ui=SwaggerUIBundle({url:"/openapi.json",dom_id:"#swagger-ui"});</script>'
            . '</body></html>';

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    private function processIsRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (!@file_exists("/proc/{$pid}/status")) {
            return false;
        }
        $status = @file_get_contents("/proc/{$pid}/status");
        if ($status === false) {
            return false;
        }
        if (preg_match('/^State:\s+Z/m', $status)) {
            return false;
        }
        return true;
    }

    private function listenerResource(array $listener): array
    {
        $running = $this->processIsRunning((int)($listener['pid'] ?? 0));
        return [
            'imei' => $listener['imei'] ?? '',
            'model' => $listener['model'] ?? '',
            'pid' => $listener['pid'] ?? 0,
            'running' => $running,
            'startedAt' => $listener['started_at'] ?? null,
            'since' => isset($listener['started_at']) ? (time() - $listener['started_at']) . 's ago' : null,
        ];
    }

    private function loadListeners(): array
    {
        $listeners = [];
        if (!$this->redis) {
            return $listeners;
        }
        try {
            $keys = $this->redis->keys(self::REDIS_PREFIX . '*');
            foreach ($keys as $key) {
                $raw = $this->redis->get($key);
                if (!$raw) {
                    continue;
                }
                $listener = json_decode($raw, true);
                if (!$listener) {
                    continue;
                }
                if (!$this->processIsRunning((int)($listener['pid'] ?? 0))) {
                    $this->redis->del($key);
                    continue;
                }
                $listeners[] = $listener;
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $listeners;
    }

    private function demoApiEnabled(): bool
    {
        $raw = strtolower(trim((string)(getenv('DEMO_API_ENABLED') ?: 'false')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
