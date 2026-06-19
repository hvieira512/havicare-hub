<?php

namespace Hub\Dashboard;

use Hub\Api\Routes\Devices;
use Hub\Api\Routes\Models;
use Hub\Api\Routes\Suppliers;
use Hub\DeviceHubServer;
use Hub\PendingDownlinkQueue;
use Hub\Registry\Whitelist;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class DashboardHttpServer
{
    private const MODEL_IMAGE_DIR = __DIR__ . '/../../var/dashboard/model-images';
    private const MODEL_IMAGE_ROUTE = '/model-images';
    private ApiRouter $apiRouter;
    private Devices $devicesApi;
    private Models $modelsApi;
    private Suppliers $suppliersApi;

    public function __construct(
        private DashboardStore $store,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ?PendingDownlinkQueue $downlinkQueue,
        private DashboardDataAccess $db,
        private string $username,
        private string $password,
    ) {
        foreach ($this->whitelist->all() as $imei => $metadata) {
            $this->store->registerDevice(
                (string)$imei,
                (string)($metadata['supplier'] ?? ''),
                (string)($metadata['model'] ?? ''),
                (string)($metadata['deviceType'] ?? 'watch'),
                (string)($metadata['licenseId'] ?? '0'),
                (string)($metadata['simNumber'] ?? ''),
                (string)($metadata['deviceId'] ?? ''),
                (string)($metadata['sourceSystem'] ?? ''),
                (string)($metadata['sourceDeviceId'] ?? '')
            );
        }

        $this->devicesApi = new Devices($this->store, $this->whitelist, $this->hub, $this->downlinkQueue, $this->db);
        $this->modelsApi = new Models($this->db);
        $this->suppliersApi = new Suppliers($this->db);
        $this->apiRouter = new ApiRouter($this->apiRoutes());
    }

    public function __invoke(ServerRequestInterface $request): Response
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->cors(new Response(204));
        }

        if (!$this->isAuthorized($request)) {
            return $this->cors(new Response(401, ['WWW-Authenticate' => 'Basic realm="Devices Hub"', 'Content-Type' => 'text/plain'], 'Unauthorized'));
        }

        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        try {
            if ($method === 'GET' && ($path === '/' || $path === '/dashboard')) {
                return $this->html($this->page());
            }
            if (str_starts_with($path, '/api/')) {
                return $this->cors($this->dispatchApi($request));
            }
            if ($method === 'GET' && preg_match('#^' . self::MODEL_IMAGE_ROUTE . '/([a-f0-9]{32}\.jpg)$#', $path, $matches) === 1) {
                return $this->modelImage($matches[1]);
            }
            if ($method === 'GET' && !str_starts_with($path, '/api/') && $path !== '/' && $path !== '/dashboard') {
                $file = __DIR__ . $path;
                if (file_exists($file) && is_file($file)) {
                    return $this->staticFile($file);
                }
            }
        } catch (\Throwable $e) {
            return $this->cors($this->json(['error' => ['code' => 'server_error', 'message' => $e->getMessage()]], 500));
        }

        return $this->cors($this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404));
    }

    private function cors(Response $response): Response
    {
        return $response->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    private function isAuthorized(ServerRequestInterface $request): bool
    {
        if ($this->username === '' || $this->password === '') {
            return true;
        }

        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Basic ')) {
            return false;
        }

        $decoded = base64_decode(substr($header, 6), true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return false;
        }

        [$username, $password] = explode(':', $decoded, 2);
        return hash_equals($this->username, $username) && hash_equals($this->password, $password);
    }

    /**
     * @return list<ApiRoute>
     */
    private function apiRoutes(): array
    {
        $factory = require __DIR__ . '/../Api/Routes/index.php';

        return $factory(
            $this->devicesApi,
            $this->modelsApi,
            $this->suppliersApi,
            fn(array $payload, int $status = 200): Response => $this->json($payload, $status),
            fn(string $body): Response => $this->html($body)
        );
    }

    private function dispatchApi(ServerRequestInterface $request): Response
    {
        $match = $this->apiRouter->match(strtoupper($request->getMethod()), $request->getUri()->getPath());
        if ($match === null) {
            return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }

        $handler = $match['route']->handler();

        return $handler($match['parameters'], $request);
    }

    private function json(array $payload, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function html(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    private function page(): string
    {
        ob_start();
        require __DIR__ . '/index.php';
        return (string) ob_get_clean();
    }

    private function staticFile(string $path): Response
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'js' => 'application/javascript',
            'css' => 'text/css',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'text/plain',
        };
        return new Response(200, ['Content-Type' => $mime], (string) file_get_contents($path));
    }

    private function modelImage(string $filename): Response
    {
        $path = self::MODEL_IMAGE_DIR . '/' . $filename;
        if (!is_file($path)) {
            return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }
        return new Response(200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=31536000, immutable'], (string)file_get_contents($path));
    }
}
