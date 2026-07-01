<?php

namespace Hub\Dashboard;

use Hub\Api\Routes\ApiUsers;
use Hub\Api\Routes\Auth;
use Hub\Api\Routes\Capabilities;
use Hub\Api\Routes\Company;
use Hub\Api\Routes\Devices;
use Hub\Api\Routes\Licenses;
use Hub\Api\Routes\Models;
use Hub\Api\Routes\Protocols;
use Hub\Api\Routes\Suppliers;
use Hub\DeviceHubServer;
use Hub\Log\Logger;
use Hub\PendingDownlinkQueue;
use Hub\Registry\Whitelist;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class DashboardHttpServer
{
    private const MODEL_IMAGE_DIR = __DIR__ . '/../../var/dashboard/model-images';
    private const MODEL_IMAGE_ROUTE = '/model-images';
    private const API_RAW_BODY_ATTR = 'apiRawBody';
    private const API_REQUEST_ID_ATTR = 'apiRequestId';
    private const API_ROUTE_PATTERN_ATTR = 'apiRoutePattern';
    private ApiRouter $apiRouter;
    private Devices $devicesApi;
    private Models $modelsApi;
    private Capabilities $capabilitiesApi;
    private Suppliers $suppliersApi;
    private ApiUsers $apiUsersApi;
    private Company $companyApi;
    private Licenses $licensesApi;
    private Protocols $protocolsApi;
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
        private bool $apiAuthRequired = true,
        private int $apiTokenTtlSeconds = 3600,
        private int $apiRefreshTokenTtlSeconds = 2592000,
    ) {
        if ($this->apiAuthRequired) {
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
        } else {
            $this->apiCredentials = [];
        }

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

        $this->devicesApi = new Devices($this->store, $this->whitelist, $this->hub, $this->downlinkQueue, $this->db);
        $this->modelsApi = new Models($this->db);
        $this->capabilitiesApi = new Capabilities($this->db);
        $this->suppliersApi = new Suppliers($this->db);
        $this->apiUsersApi = new ApiUsers($this->db);
        $this->companyApi = new Company($this->db);
        $this->licensesApi = new Licenses($this->db);
        $this->protocolsApi = new Protocols();
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
            $requestId = $this->apiRequestId($request);
            $rawBody = (string)$request->getBody();
            $request = $request
                ->withAttribute(self::API_REQUEST_ID_ATTR, $requestId)
                ->withAttribute(self::API_RAW_BODY_ATTR, $rawBody);
            $startedAt = microtime(true);
            $match = $this->apiRouter->match($method, $path);
            $routePattern = $match !== null ? $match['route']->pattern() : null;
            $authResolution = $this->isPublicApiPath($path)
                ? ['context' => null, 'state' => 'public_login']
                : $this->resolveApiAuthContext($request);
            $authContext = $authResolution['context'];
            $authState = $authResolution['state'];

            if (!$this->isPublicApiPath($path)) {
                if ($authContext === null) {
                    $response = $this->cors($this->json(['error' => ['code' => 'unauthorized', 'message' => 'Unauthorized']], 401));
                    $response = $response->withHeader('X-Request-Id', $requestId);
                    $this->logApiRequest($request, $response, $startedAt, $routePattern, $authContext, $authState);
                    return $response;
                }
            }

            if ($routePattern !== null) {
                $request = $request->withAttribute(self::API_ROUTE_PATTERN_ATTR, $routePattern);
            }
        } else {
            $authContext = null;
        }

        try {
            if ($this->isApiPath($path)) {
                $response = $this->cors($this->dispatchApi($request, $authContext, $match ?? null));
                $response = $response->withHeader('X-Request-Id', $requestId);
                $this->logApiRequest($request, $response, $startedAt, $routePattern, $authContext, $authState);
                return $response;
            }
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
            if ($this->isApiPath($path)) {
                Logger::channel('api')->error('Unhandled API exception', [
                    'request_id' => $request->getAttribute(self::API_REQUEST_ID_ATTR, ''),
                    'method' => $method,
                    'path' => $path,
                    'route' => $request->getAttribute(self::API_ROUTE_PATTERN_ATTR, $routePattern ?? null),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $response = $this->cors($this->json(['error' => ['code' => 'server_error', 'message' => $e->getMessage()]], 500));
                $response = $response->withHeader('X-Request-Id', $request->getAttribute(self::API_REQUEST_ID_ATTR, ''));
                $this->logApiRequest(
                    $request,
                    $response,
                    $startedAt ?? microtime(true),
                    $request->getAttribute(self::API_ROUTE_PATTERN_ATTR, $routePattern ?? null),
                    $authContext ?? null,
                    $authState ?? 'error'
                );
                return $response;
            }

            return $this->cors($this->json(['error' => ['code' => 'server_error', 'message' => $e->getMessage()]], 500));
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

    private function isApiPath(string $path): bool
    {
        return str_starts_with($path, '/api/');
    }

    private function isPublicApiPath(string $path): bool
    {
        return in_array($path, [
            '/api/auth/login',
            '/api/docs',
            '/api/openapi.json',
        ], true);
    }

    private function resolveApiAuthContext(ServerRequestInterface $request): array
    {
        if ($this->apiCredentials === []) {
            return [
                'context' => new ApiAuthContext(null, 'anonymous', ApiAuthContext::ROLE_HUB_ADMIN),
                'state' => 'anonymous_admin',
            ];
        }

        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $context = $this->tokens->context((string)$matches[1]);
            return ['context' => $context, 'state' => $context !== null ? 'bearer' : 'invalid_bearer'];
        }

        parse_str((string)$request->getUri()->getQuery(), $params);
        $queryToken = trim((string)($params['access_token'] ?? ''));
        if ($queryToken === '') {
            return ['context' => null, 'state' => 'missing'];
        }

        $context = $this->tokens->context($queryToken);
        return ['context' => $context, 'state' => $context !== null ? 'query_token' : 'invalid_query_token'];
    }

    /**
     * @return list<ApiRoute>
     */
    private function apiRoutes(): array
    {
        $factory = require __DIR__ . '/../Api/Routes/index.php';

        return $factory(
            new Auth($this->apiCredentials, $this->tokens, $this->db, $this->apiTokenTtlSeconds, $this->apiRefreshTokenTtlSeconds),
            $this->devicesApi,
            $this->modelsApi,
            $this->capabilitiesApi,
            $this->suppliersApi,
            $this->apiUsersApi,
            $this->companyApi,
            $this->licensesApi,
            $this->protocolsApi,
            fn(array $payload, int $status = 200): Response => $this->json($payload, $status),
            fn(string $body): Response => $this->html($body)
        );
    }

    private function dispatchApi(ServerRequestInterface $request, ?ApiAuthContext $authContext, ?array $match = null): Response
    {
        $match = $match ?? $this->apiRouter->match(strtoupper($request->getMethod()), $request->getUri()->getPath());
        if ($match === null) {
            return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }

        if ($authContext !== null && !$this->isRouteAllowed($match['route'], $authContext)) {
            return $this->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
        }

        if ($authContext !== null) {
            $request = $request->withAttribute('apiAuth', $authContext);
        }
        $request = $request->withAttribute(self::API_ROUTE_PATTERN_ATTR, $match['route']->pattern());

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
            'PUT /api/devices/{imei}',
            'GET /api/devices/{imei}/stream',
            'POST /api/devices/{imei}/requests',
            'PATCH /api/devices/{imei}/association',
            'DELETE /api/devices/{imei}/association',
            'GET /api/commands/{id}',
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
            $dashboardApiToken = $this->tokens->issueTokenPair(
                $this->username,
                ApiAuthContext::ROLE_HUB_ADMIN,
                $this->apiTokenTtlSeconds,
                $this->apiRefreshTokenTtlSeconds
            );
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

    private function apiRequestId(ServerRequestInterface $request): string
    {
        $requestId = trim($request->getHeaderLine('X-Request-Id'));
        if ($requestId !== '') {
            return $requestId;
        }

        return bin2hex(random_bytes(8));
    }

    private function logApiRequest(
        ServerRequestInterface $request,
        Response $response,
        float $startedAt,
        ?string $routePattern,
        ?ApiAuthContext $authContext,
        string $authState
    ): void {
        $query = $request->getUri()->getQuery();
        $serverParams = $request->getServerParams();
        $status = $response->getStatusCode();
        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');

        Logger::channel('api')->log($level, 'API request completed', [
            'request_id' => (string)$request->getAttribute(self::API_REQUEST_ID_ATTR, ''),
            'method' => strtoupper($request->getMethod()),
            'path' => $request->getUri()->getPath(),
            'query' => $query,
            'route' => $routePattern,
            'status' => $status,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'remote_ip' => (string)($serverParams['REMOTE_ADDR'] ?? ''),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'auth_state' => $authState,
            'username' => $authContext?->username,
            'role' => $authContext?->role,
            'license_id' => $authContext?->licenseId,
            'request_body' => (string)$request->getAttribute(self::API_RAW_BODY_ATTR, ''),
        ]);
    }
}
