<?php

namespace Hub\Dashboard;

use Hub\Api\Routes\ApiUsers;
use Hub\Api\Routes\Auth;
use Hub\Api\Routes\Company;
use Hub\Api\Routes\Devices;
use Hub\Api\Routes\Licenses;
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
    private ApiUsers $apiUsersApi;
    private Company $companyApi;
    private Licenses $licensesApi;
    private array $apiCredentials = [];

    public function __construct(
        private DashboardStore $store,
        private ApiTokenStore $tokens,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ?PendingDownlinkQueue $downlinkQueue,
        private DashboardDataAccess $db,
        private string $username,
        private string $password,
        private string $clientUsername = '',
        private string $clientPassword = '',
        private int $apiTokenTtlSeconds = 3600,
    ) {
        $this->apiCredentials = array_values(array_filter([
            [
                'username' => $this->username,
                'password' => $this->password,
                'role' => ApiAuthContext::ROLE_HUB_ADMIN,
            ],
            [
                'username' => $this->clientUsername,
                'password' => $this->clientPassword,
                'role' => ApiAuthContext::ROLE_LICENSE_CLIENT,
            ],
        ], static fn (array $credential): bool => trim((string)$credential['username']) !== '' && (string)$credential['password'] !== ''));

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
                (string)($metadata['sourceDeviceId'] ?? ''),
                (string)($metadata['company'] ?? 'null')
            );
        }

        $this->devicesApi = new Devices($this->store, $this->whitelist, $this->hub, $this->downlinkQueue, $this->db);
        $this->modelsApi = new Models($this->db);
        $this->suppliersApi = new Suppliers($this->db);
        $this->apiUsersApi = new ApiUsers($this->db);
        $this->companyApi = new Company($this->db);
        $this->licensesApi = new Licenses($this->db);
        $this->apiRouter = new ApiRouter($this->apiRoutes());
    }

    public function __invoke(ServerRequestInterface $request): Response
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        if ($method === 'OPTIONS') {
            return $this->cors(new Response(204));
        }

        if ($this->isApiPath($path)) {
            if ($path !== '/api/auth/login') {
                $authContext = $this->apiAuthContext($request);
                if ($authContext === null) {
                    return $this->cors($this->json(['error' => ['code' => 'unauthorized', 'message' => 'Unauthorized']], 401));
                }
            } else {
                $authContext = null;
            }

            if ($path !== '/api/auth/login' && $authContext === null) {
                return $this->cors($this->json(['error' => ['code' => 'unauthorized', 'message' => 'Unauthorized']], 401));
            }
        } elseif (!$this->isDashboardAuthorized($request)) {
            return $this->cors(new Response(401, ['WWW-Authenticate' => 'Basic realm="Devices Hub"', 'Content-Type' => 'text/plain'], 'Unauthorized'));
        } else {
            $authContext = null;
        }

        try {
            if ($method === 'GET' && ($path === '/' || $path === '/dashboard')) {
                return $this->html($this->page());
            }
            if (str_starts_with($path, '/api/')) {
                return $this->cors($this->dispatchApi($request, $authContext));
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

    private function isApiPath(string $path): bool
    {
        return str_starts_with($path, '/api/');
    }

    private function apiAuthContext(ServerRequestInterface $request): ?ApiAuthContext
    {
        if ($this->apiCredentials === []) {
            return new ApiAuthContext(null, 'anonymous', ApiAuthContext::ROLE_HUB_ADMIN);
        }

        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        return $this->tokens->context((string)$matches[1]);
    }

    private function isDashboardAuthorized(ServerRequestInterface $request): bool
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
            new Auth($this->apiCredentials, $this->tokens, $this->db, $this->apiTokenTtlSeconds),
            $this->devicesApi,
            $this->modelsApi,
            $this->suppliersApi,
            $this->apiUsersApi,
            $this->companyApi,
            $this->licensesApi,
            fn(array $payload, int $status = 200): Response => $this->json($payload, $status),
            fn(string $body): Response => $this->html($body)
        );
    }

    private function dispatchApi(ServerRequestInterface $request, ?ApiAuthContext $authContext): Response
    {
        $match = $this->apiRouter->match(strtoupper($request->getMethod()), $request->getUri()->getPath());
        if ($match === null) {
            return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }

        if ($authContext !== null && !$this->isRouteAllowed($match['route'], $authContext)) {
            return $this->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
        }

        if ($authContext !== null) {
            $request = $request->withAttribute('apiAuth', $authContext);
        }

        $handler = $match['route']->handler();

        return $handler($match['parameters'], $request);
    }

    private function isRouteAllowed(ApiRoute $route, ApiAuthContext $authContext): bool
    {
        if ($authContext->isAdmin()) {
            return true;
        }

        return in_array($route->method() . ' ' . $route->pattern(), [
            'GET /api/devices',
            'GET /api/devices/{imei}',
            'POST /api/devices/{imei}/commands',
            'GET /api/commands/{id}',
            'GET /api/devices/{imei}/configuration',
            'PUT /api/devices/{imei}/configuration',
            'POST /api/devices/{imei}/configuration/{key}/apply',
        ], true);
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
        $dashboardApiToken = null;
        if (isset($this->tokens, $this->username, $this->password) && $this->apiCredentials !== [] && $this->username !== '' && $this->password !== '') {
            $dashboardApiToken = $this->tokens->issue($this->username, ApiAuthContext::ROLE_HUB_ADMIN, $this->apiTokenTtlSeconds);
        }

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

    private function modelImage(string $filename): Response
    {
        $path = self::MODEL_IMAGE_DIR . '/' . $filename;
        if (!is_file($path)) {
            return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }
        return new Response(200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=31536000, immutable'], (string)file_get_contents($path));
    }
}
