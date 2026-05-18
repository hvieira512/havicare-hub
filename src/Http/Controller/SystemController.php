<?php

namespace App\Http\Controller;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use App\Http\OpenApiSpec;

class SystemController extends Controller
{
    private array $demoListeners = [];

    public function healthCheck(): Response
    {
        $dbOk = $this->pdo !== null;
        $redisOk = $this->redis !== null && $this->redis->isAvailable();

        return $this->jsonResponse([
            'status' => ($dbOk ? 'ok' : 'degraded'),
            'services' => [
                'mysql' => $dbOk,
                'redis' => $redisOk,
                'watchServerAttached' => $this->watchServer !== null,
            ],
            'onlineDevices' => $this->watchServer !== null ? $this->watchServer->onlineDeviceCount() : 0,
            'time' => time(),
        ]);
    }

    public function metricsEndpoint(): Response
    {
        $payload = [
            'onlineDevices' => $this->watchServer !== null ? $this->watchServer->onlineDeviceCount() : 0,
            'knownModels' => \App\Registry\DeviceCapabilities::allModels(),
            'totalDevices' => count($this->whitelist()->all()),
            'time' => time(),
        ];

        return $this->jsonResponse($payload);
    }

    public function simulateDeviceEvent(ServerRequestInterface $request): Response
    {
        if ($this->pdo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        $imei = trim((string)($body['imei'] ?? ''));
        $type = trim((string)($body['type'] ?? ''));
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if ($imei === '' || $type === '') {
            return $this->errorResponse('invalid_request', 'imei and type are required', 400);
        }

        if ($this->eventsRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'Event repository is not available', 503);
        }

        $event = [
            'imei' => $imei,
            'nativeType' => $type,
            'feature' => null,
            'nativePayload' => $data,
            'receivedAt' => (int)round(microtime(true) * 1000),
        ];
        $eventId = $this->eventsRepo->insert($event);

        if ($this->redis !== null && $this->redis->isAvailable()) {
            $this->redis->eventPush($event);
        }
        if ($this->watchServer !== null) {
            $this->watchServer->ingestEvent($event, $eventId);
        }

        return $this->jsonResponse([
            'data' => [
                'status' => 'simulated',
                'imei' => $imei,
                'type' => $type,
                'id' => $eventId,
            ],
        ], 201);
    }

    public function startDemoListener(ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        $imei = trim((string)($body['imei'] ?? ''));
        if ($imei === '') {
            return $this->errorResponse('invalid_request', 'imei is required', 400);
        }

        if (isset($this->demoListeners[$imei])) {
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

        $this->demoListeners[$imei] = ['pid' => $pid, 'imei' => $imei, 'model' => $model, 'started_at' => time()];

        return $this->jsonResponse(['data' => $this->listenerResource($this->demoListeners[$imei], true)], 201);
    }

    public function stopDemoListener(string $imei): Response
    {
        if (!isset($this->demoListeners[$imei])) {
            return $this->errorResponse('not_found', 'No demo listener for this IMEI', 404);
        }

        $listener = $this->demoListeners[$imei];
        if ($listener['pid'] > 0) {
            exec('kill ' . (int)$listener['pid'] . ' 2>/dev/null');
        }

        unset($this->demoListeners[$imei]);

        return $this->jsonResponse(['data' => $this->listenerResource($listener, false)]);
    }

    public function demoListeners(): Response
    {
        return $this->jsonResponse([
            'data' => array_values(array_map(
                fn(array $l): array => $this->listenerResource($l, $this->processIsRunning($l['pid'])),
                $this->demoListeners
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
        $output = trim((string)shell_exec("ps -p $pid -o pid= 2>/dev/null"));
        return $output !== '';
    }

    private function listenerResource(array $listener, ?bool $running = null): array
    {
        if ($running === null) {
            $running = $this->processIsRunning($listener['pid']);
        }
        return [
            'imei' => $listener['imei'],
            'model' => $listener['model'],
            'pid' => $listener['pid'],
            'running' => $running,
            'startedAt' => $listener['started_at'] ?? null,
            'since' => isset($listener['started_at']) ? (time() - $listener['started_at']) . 's ago' : null,
        ];
    }
}
