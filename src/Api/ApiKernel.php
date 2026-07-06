<?php

namespace Hub\Api;

use Hub\Api\Controllers\ApiUserController;
use Hub\Api\Controllers\AuthController;
use Hub\Api\Controllers\CapabilityController;
use Hub\Api\Controllers\CompanyController;
use Hub\Api\Controllers\DeviceController;
use Hub\Api\Controllers\LicenseController;
use Hub\Api\Controllers\ModelController;
use Hub\Api\Controllers\ProtocolController;
use Hub\Api\Controllers\SupplierController;
use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Auth\BearerTokenResolver;
use Hub\Api\Auth\RouteAccessPolicy;
use Hub\Api\Http\CorsPolicy;
use Hub\Api\Http\ErrorStatusMapper;
use Hub\Api\Http\HtmlResponder;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Routing\ApiRouter;
use Hub\Api\Services\ApiUserService;
use Hub\Api\Services\AuthService;
use Hub\Api\Services\CapabilityService;
use Hub\Api\Services\CompanyService;
use Hub\Api\Services\DeviceService;
use Hub\Api\Services\LicenseService;
use Hub\Api\Services\ModelService;
use Hub\Api\Services\ProtocolService;
use Hub\Api\Services\SupplierService;
use Hub\Log\Logger;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class ApiKernel
{
    private ApiRouter $router;

    public function __construct(
        private bool $apiAuthRequired,
        private AuthService $auth,
        private DeviceService $devices,
        private ModelService $models,
        private CapabilityService $capabilities,
        private SupplierService $suppliers,
        private ApiUserService $apiUsers,
        private CompanyService $company,
        private LicenseService $licenses,
        private ProtocolService $protocols,
        private JsonResponder $json,
        private HtmlResponder $html,
        private CorsPolicy $cors,
        private ErrorStatusMapper $statusMapper,
        private BearerTokenResolver $bearerTokenResolver,
        private RouteAccessPolicy $routeAccessPolicy,
    ) {
        $this->router = new ApiRouter($this->apiRoutes());
    }

    public function handle(ServerRequestInterface $request): Response
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $requestId = $request->getHeaderLine('X-Request-Id') ?: RequestContext::requestId($request) ?: bin2hex(random_bytes(16));
        $rawBody = (string)$request->getBody();
        $request = $request
            ->withAttribute(RequestContext::ATTR_REQUEST_ID, $requestId)
            ->withAttribute(RequestContext::ATTR_RAW_BODY, $rawBody);
        $startedAt = microtime(true);
        $match = $this->router->match($method, $path);
        $routePattern = $match !== null ? $match['route']->pattern() : null;
        $authResolution = $this->isPublicApiPath($path)
            ? ['context' => null, 'state' => 'public_login']
            : $this->resolveApiAuthContext($request);
        $authContext = $authResolution['context'];
        $authState = $authResolution['state'];

        if (!$this->isPublicApiPath($path) && $authContext === null) {
            $response = $this->cors->apply($this->json->respond(['error' => ['code' => 'unauthorized', 'message' => 'Unauthorized']], 401));
            $response = $response->withHeader('X-Request-Id', $requestId);
            $this->safeLogApiRequest($request, $response, $startedAt, $routePattern, $authContext, $authState);
            return $response;
        }

        if ($routePattern !== null) {
            $request = $request->withAttribute(RequestContext::ATTR_ROUTE_PATTERN, $routePattern);
        }

        try {
            $response = $this->cors->apply($this->dispatch($request, $authContext, $match));
            $response = $response->withHeader('X-Request-Id', $requestId);
            $this->safeLogApiRequest($request, $response, $startedAt, $routePattern, $authContext, $authState);
            return $response;
        } catch (\Throwable $e) {
            Logger::channel('api')->error('Unhandled API exception', [
                'request_id' => $requestId,
                'method' => $method,
                'path' => $path,
                'route' => $routePattern,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $response = $this->cors->apply($this->json->respond(['error' => ['code' => 'server_error', 'message' => $e->getMessage()]], 500));
            $response = $response->withHeader('X-Request-Id', $requestId);
            $this->safeLogApiRequest($request, $response, $startedAt, $routePattern, $authContext, 'error');
            return $response;
        }
    }

    /**
     * @return list<ApiRoute>
     */
    private function apiRoutes(): array
    {
        $auth = new AuthController($this->auth, $this->json);
        $devices = new DeviceController($this->devices, $this->json, $this->statusMapper);
        $models = new ModelController($this->models, $this->json, $this->statusMapper);
        $capabilities = new CapabilityController($this->capabilities, $this->json, $this->statusMapper);
        $suppliers = new SupplierController($this->suppliers, $this->json, $this->statusMapper);
        $apiUsers = new ApiUserController($this->apiUsers, $this->json, $this->statusMapper);
        $company = new CompanyController($this->company, $this->json, $this->statusMapper);
        $licenses = new LicenseController($this->licenses, $this->json, $this->statusMapper);
        $protocols = new ProtocolController($this->protocols, $this->json, $this->statusMapper);
        $json = fn(array $payload, int $status = 200): Response => $this->json->respond($payload, $status);
        $html = fn(string $body): Response => $this->html->respond($body);

        return [
            ...((require __DIR__ . '/Routes/AuthRoutes.php')($auth)),
            ...((require __DIR__ . '/Routes/DeviceRoutes.php')($devices)),
            ...((require __DIR__ . '/Routes/ModelRoutes.php')($models)),
            ...((require __DIR__ . '/Routes/CapabilityRoutes.php')($capabilities)),
            ...((require __DIR__ . '/Routes/SupplierRoutes.php')($suppliers)),
            ...((require __DIR__ . '/Routes/ApiUserRoutes.php')($apiUsers)),
            ...((require __DIR__ . '/Routes/CompanyRoutes.php')($company)),
            ...((require __DIR__ . '/Routes/LicenseRoutes.php')($licenses)),
            ...((require __DIR__ . '/Routes/ProtocolRoutes.php')($protocols)),
            ...((require __DIR__ . '/Routes/SystemRoutes.php')($json, $html)),
        ];
    }

    private function resolveApiAuthContext(ServerRequestInterface $request): array
    {
        if (!$this->apiAuthRequired) {
            return [
                'context' => new ApiAuthContext(null, 'anonymous', ApiAuthContext::ROLE_HUB_ADMIN),
                'state' => 'anonymous_admin',
            ];
        }

        $context = $this->bearerTokenResolver->resolve($request);
        if ($context === null) {
            return ['context' => null, 'state' => 'missing'];
        }

        return ['context' => $context, 'state' => 'bearer'];
    }

    private function dispatch(ServerRequestInterface $request, ?ApiAuthContext $authContext, ?array $match = null): Response
    {
        $match = $match ?? $this->router->match(strtoupper($request->getMethod()), $request->getUri()->getPath());
        if ($match === null) {
            return $this->json->respond(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }

        if ($authContext !== null && !$this->routeAccessPolicy->allows($authContext, $match['route']->method(), $match['route']->pattern())) {
            return $this->json->respond(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
        }

        if ($authContext !== null) {
            $request = $request->withAttribute(RequestContext::ATTR_AUTH, $authContext);
        }
        $request = $request->withAttribute(RequestContext::ATTR_ROUTE_PATTERN, $match['route']->pattern());

        $handler = $match['route']->handler();
        return $this->invokeHandler($handler, $match['parameters'], $request);
    }

    private function invokeHandler(callable $handler, array $parameters, ServerRequestInterface $request): mixed
    {
        $reflection = is_array($handler)
            ? new \ReflectionMethod($handler[0], $handler[1])
            : new \ReflectionFunction(\Closure::fromCallable($handler));
        $count = $reflection->getNumberOfParameters();
        if ($count === 0) {
            return $handler();
        }
        if ($count === 1) {
            $parameter = $reflection->getParameters()[0] ?? null;
            $arg = $parameter !== null && $this->expectsRequest($parameter) ? $request : $parameters;

            return $handler($arg);
        }

        return $handler($parameters, $request);
    }

    private function expectsRequest(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        $name = $type->getName();
        if ($name === ServerRequestInterface::class) {
            return true;
        }

        return is_a($name, ServerRequestInterface::class, true);
    }

    private function isPublicApiPath(string $path): bool
    {
        return in_array($path, [
            '/api/auth/login',
            '/api/docs',
            '/api/openapi.json',
        ], true);
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
            'request_id' => (string)$request->getAttribute(RequestContext::ATTR_REQUEST_ID, ''),
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
            'request_body' => (string)$request->getAttribute(RequestContext::ATTR_RAW_BODY, ''),
        ]);
    }

    private function safeLogApiRequest(
        ServerRequestInterface $request,
        Response $response,
        float $startedAt,
        ?string $routePattern,
        ?ApiAuthContext $authContext,
        string $authState
    ): void {
        try {
            $this->logApiRequest($request, $response, $startedAt, $routePattern, $authContext, $authState);
        } catch (\Throwable $e) {
            Logger::channel('api')->warning('Failed to log API request completion', [
                'request_id' => (string)$request->getAttribute(RequestContext::ATTR_REQUEST_ID, ''),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
