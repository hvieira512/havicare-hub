<?php

namespace Hub\Dashboard;

use Hub\Api\ApiKernel;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Services\ApiUserService;
use Hub\Api\Services\AuthService;
use Hub\Api\Services\CapabilityService;
use Hub\Api\Repository\CapabilityDiscoveryRepository;
use Hub\Api\Services\CapabilityDiscoveryService;
use Hub\Api\Services\CompanyService;
use Hub\Api\Services\DeviceService;
use Hub\Api\Services\DashboardNotificationService;
use Hub\Api\Services\LicenseService;
use Hub\Api\Services\ModelService;
use Hub\Api\Services\ProtocolService;
use Hub\Api\Services\SupplierService;
use Hub\Api\Repository\ApiDataAccess;
use Hub\DeviceHubServer;
use Hub\PendingDownlinkQueue;
use Hub\Registry\Whitelist;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class DashboardHttpServer
{
    private const MODEL_IMAGE_DIR = __DIR__ . '/../../var/dashboard/model-images';
    private const MODEL_IMAGE_ROUTE = '/model-images';
    private const PUBLIC_ASSET_EXTENSIONS = ['css', 'ico', 'jpeg', 'jpg', 'js', 'png', 'svg', 'woff2'];
    private ApiKernel $apiKernel;

    public function __construct(
        private DashboardStore $store,
        private ApiTokenStore $tokens,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ?PendingDownlinkQueue $downlinkQueue,
        private ApiDataAccess $db,
        private bool $apiAuthRequired = true,
        private int $apiTokenTtlSeconds = 3600,
        private int $apiRefreshTokenTtlSeconds = 2592000,
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
                (string)($metadata['company'] ?? 'null')
            );
        }

        $deviceService = new DeviceService($this->store, $this->whitelist, $this->hub, $this->downlinkQueue, $this->db);
        $this->apiKernel = new ApiKernel(
            $this->apiAuthRequired,
            new AuthService($this->tokens, $this->db, $this->apiTokenTtlSeconds, $this->apiRefreshTokenTtlSeconds),
            $deviceService,
            new ModelService($this->db),
            new CapabilityService($this->db),
            new CapabilityDiscoveryService(
                $this->db,
                $deviceService,
                new CapabilityDiscoveryRepository(dirname(__DIR__, 2) . '/var/dashboard/capability-discovery'),
            ),
            new SupplierService($this->db),
            new ApiUserService($this->db),
            new CompanyService($this->db),
            new LicenseService($this->db),
            new ProtocolService(),
            new DashboardNotificationService($this->db),
            new \Hub\Api\Http\JsonResponder(),
            new \Hub\Api\Http\HtmlResponder(),
            new \Hub\Api\Http\CorsPolicy(),
            new \Hub\Api\Http\ErrorStatusMapper(),
            new \Hub\Api\Auth\BearerTokenResolver($this->tokens),
            new \Hub\Api\Auth\RouteAccessPolicy(),
        );
    }

    public function __invoke(ServerRequestInterface $request): Response
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        if ($method === 'OPTIONS') {
            return $this->cors(new Response(204));
        }

        if (str_starts_with($path, '/api/')) {
            return $this->apiKernel->handle($request);
        }

        try {
            if ($method === 'GET' && ($path === '/' || $path === '/dashboard')) {
                return $this->html($this->page());
            }
            if ($method === 'GET' && preg_match('#^' . self::MODEL_IMAGE_ROUTE . '/([a-f0-9]{32}\.jpg)$#', $path, $matches) === 1) {
                return $this->modelImage($matches[1]);
            }
            if ($method === 'GET' && !str_starts_with($path, '/api/') && $path !== '/' && $path !== '/dashboard') {
                $file = $this->publicAssetPath($path);
                if ($file !== null) {
                    return $this->staticFile($file);
                }
            }
        } catch (\Throwable) {
            return $this->cors($this->json([
                'error' => ['code' => 'server_error', 'message' => 'Internal server error'],
            ], 500));
        }

        return $this->cors($this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404));
    }

    private function cors(Response $response): Response
    {
        return $response->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Max-Age', '86400');
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
        $dashboardApiAuthRequired = isset($this->apiAuthRequired) ? $this->apiAuthRequired : true;

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
            'woff2' => 'font/woff2',
            default => 'text/plain',
        };
        return new Response(200, ['Content-Type' => $mime], (string) file_get_contents($path));
    }

    private function publicAssetPath(string $requestPath): ?string
    {
        $requestPath = rawurldecode($requestPath);
        if (str_contains($requestPath, "\0") || str_contains($requestPath, '\\')) {
            return null;
        }

        $routes = [
            '/main.css' => [__DIR__, 'main.css'],
            '/main.js' => [__DIR__, 'main.js'],
        ];
        if (isset($routes[$requestPath])) {
            [$root, $relativePath] = $routes[$requestPath];
            return $this->assetWithinRoot($root, $relativePath);
        }

        foreach (['/assets/' => __DIR__ . '/assets', '/dashboard/' => __DIR__ . '/dashboard'] as $prefix => $root) {
            if (str_starts_with($requestPath, $prefix)) {
                return $this->assetWithinRoot($root, substr($requestPath, strlen($prefix)));
            }
        }

        return null;
    }

    private function assetWithinRoot(string $root, string $relativePath): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $realRoot = realpath($root);
        $realPath = realpath($root . '/' . ltrim($relativePath, '/'));
        if ($realRoot === false || $realPath === false || !is_file($realPath)) {
            return null;
        }

        $rootPrefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath, $rootPrefix)) {
            return null;
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        return in_array($extension, self::PUBLIC_ASSET_EXTENSIONS, true) ? $realPath : null;
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
