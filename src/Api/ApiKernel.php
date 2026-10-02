<?php

declare(strict_types=1);

namespace Hub\Api;

use Hub\Api\Controllers\AuthController;
use Hub\Api\Controllers\DeviceController;
use Hub\Api\Controllers\StreamController;
use Hub\Device\MessageFanout;
use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Auth\BearerTokenResolver;
use Hub\Api\Auth\RouteAccessPolicy;
use Hub\Api\Http\ApiError;
use Hub\Api\Http\HtmlResponder;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\Middleware\ApiLogContext;
use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Routing\ApiRouter;
use Hub\Log\Logger;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

final class ApiKernel
{
    // Só catálogos: o estado de ligação de um dispositivo muda de segundos a segundos e é
    // precisamente o que a dashboard existe para mostrar.
    private const REVALIDATED_ROUTES = [
        '/api/models',
        '/api/models/{id:\d+}',
        '/api/models/template',
        '/api/capabilities',
        '/api/device-types/suppliers/models',
        '/api/device-types/suppliers',
        '/api/protocols',
        '/api/protocols/{protocol}/config-catalog',
        '/api/suppliers',
        '/api/companies',
        '/api/licenses',
    ];

    private ApiRouter $router;

    public function __construct(
        private bool $apiAuthRequired,
        private ApiServices $services,
        private JsonResponder $json,
        private HtmlResponder $html,
        private BearerTokenResolver $bearerTokenResolver,
        private RouteAccessPolicy $routeAccessPolicy,
        private ?MessageFanout $messages = null,
        private int $maxOpenStreams = 200,
        private int $maxOpenStreamsPerUser = 5,
    ) {
        $this->messages ??= new MessageFanout();
        $this->router = new ApiRouter($this->apiRoutes());
    }

    /**
     * O CORS, o `X-Request-Id` e o registo do pedido saíram daqui para o
     * `Hub\Api\Http\Middleware`. A resolução da identidade ficou: alimenta ao mesmo tempo o
     * registo e a política de acesso à rota, e separá-la obrigava a correr o encaminhamento
     * duas vezes -- num middleware para saber a rota e aqui para a despachar.
     */
    public function handle(ServerRequestInterface $request): Response|PromiseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $requestId = RequestContext::requestId($request);
        $logContext = $request->getAttribute(ApiLogContext::ATTRIBUTE);
        $logContext = $logContext instanceof ApiLogContext ? $logContext : null;
        $match = $this->router->match($method, $path);
        $routePattern = $match !== null ? $match['route']->pattern() : null;
        $authResolution = $this->isPublicApiPath($path)
            ? ['context' => null, 'state' => 'public_login']
            : $this->resolveApiAuthContext($request);
        $authContext = $authResolution['context'];
        $authState = $authResolution['state'];
        $logContext?->describe($routePattern, $authContext, $authState);

        if (!$this->isPublicApiPath($path) && $authContext === null) {
            return $this->json->result(ApiError::unauthorized()->toArray());
        }

        if ($routePattern !== null) {
            $request = $request->withAttribute(RequestContext::ATTR_ROUTE_PATTERN, $routePattern);
        }

        $fail = fn(\Throwable $e): Response => $this->unhandled($e, $requestId, $method, $path, $routePattern, $logContext, $authContext);

        try {
            $response = $this->dispatch($request, $authContext, $match);

            // Uma rota que fala com um serviço de terceiros devolve a promessa em vez de
            // esperar por ele: o processo tem um event loop só, e a ingestão TCP e o MQTT
            // param enquanto alguém aqui bloqueia. O que vem a seguir -- o `ETag` dos
            // catálogos e o registo do que rebenta -- é o mesmo nos dois caminhos.
            if ($response instanceof PromiseInterface) {
                return $response->then(
                    fn(Response $resolved): Response => $this->revalidatedCatalogResponse($request, $resolved, $method, $routePattern),
                    static fn(mixed $error): Response => $fail($error instanceof \Throwable ? $error : new \RuntimeException((string)$error)),
                );
            }

            return $this->revalidatedCatalogResponse($request, $response, $method, $routePattern);
        } catch (\Throwable $e) {
            return $fail($e);
        }
    }

    /** O 500 com rasto: o cliente leva o `requestId` e o journal leva o resto. */
    private function unhandled(
        \Throwable $e,
        string $requestId,
        string $method,
        string $path,
        ?string $routePattern,
        ?ApiLogContext $logContext,
        ?ApiAuthContext $authContext,
    ): Response {
        Logger::channel('api')->error('Unhandled API exception', [
            'request_id' => $requestId,
            'method' => $method,
            'path' => $path,
            'route' => $routePattern,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
        $logContext?->describe($routePattern, $authContext, 'error');
        $error = ApiError::serverError()->toArray();
        $error['error']['requestId'] = $requestId;

        return $this->json->result($error);
    }

    /**
     * @return list<ApiRoute>
     */
    private function apiRoutes(): array
    {
        $auth = new AuthController($this->services->auth, $this->json);
        $devices = new DeviceController($this->services->devices, $this->json);
        // Construído uma vez, como os restantes: os tetos de ligações abertas são estado deste
        // controlador, e um por pedido não contava nada.
        $stream = new StreamController(
            $this->messages,
            $this->json,
            $this->maxOpenStreams,
            $this->maxOpenStreamsPerUser,
        );
        $json = fn(array $payload, int $status = 200): Response => $this->json->respond($payload, $status);
        $html = fn(string $body): Response => $this->html->respond($body);

        return [
            ...((require __DIR__ . '/Routes/AuthRoutes.php')($auth)),
            ...((require __DIR__ . '/Routes/StreamRoutes.php')($stream)),
            ...((require __DIR__ . '/Routes/DeviceRoutes.php')($devices)),
            ...((require __DIR__ . '/Routes/ModelRoutes.php')($this->services->models)),
            ...((require __DIR__ . '/Routes/CapabilityRoutes.php')($this->services->capabilities)),
            ...((require __DIR__ . '/Routes/CapabilityDiscoveryRoutes.php')($this->services->capabilityDiscovery)),
            ...((require __DIR__ . '/Routes/SupplierRoutes.php')($this->services->suppliers)),
            ...((require __DIR__ . '/Routes/ApiUserRoutes.php')($this->services->apiUsers)),
            ...((require __DIR__ . '/Routes/CompanyRoutes.php')($this->services->company)),
            ...((require __DIR__ . '/Routes/LicenseRoutes.php')($this->services->licenses)),
            ...((require __DIR__ . '/Routes/RadarCredentialsRoutes.php')($this->services->radarCredentials)),
            ...((require __DIR__ . '/Routes/RadarLayoutRoutes.php')($this->services->radarLayouts)),
            ...((require __DIR__ . '/Routes/ProtocolRoutes.php')($this->services->protocols)),
            ...((require __DIR__ . '/Routes/DashboardNotificationRoutes.php')($this->services->notifications)),
            ...((require __DIR__ . '/Routes/DenylistRoutes.php')($this->services->denylist)),
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

    private function dispatch(ServerRequestInterface $request, ?ApiAuthContext $authContext, ?array $match = null): Response|PromiseInterface
    {
        $match = $match ?? $this->router->match(strtoupper($request->getMethod()), $request->getUri()->getPath());
        if ($match === null) {
            return $this->json->result(ApiError::routeNotFound()->toArray());
        }

        if ($authContext !== null && !$this->routeAccessPolicy->allows($authContext, $match['route']->method(), $match['route']->pattern())) {
            return $this->json->result(ApiError::forbidden()->toArray());
        }

        if ($authContext !== null) {
            $request = $request->withAttribute(RequestContext::ATTR_AUTH, $authContext);
        }
        $route = $match['route'];
        $request = $request->withAttribute(RequestContext::ATTR_ROUTE_PATTERN, $route->pattern());

        $bodyMode = $route->body();
        if ($bodyMode !== null) {
            $body = $bodyMode === ApiRoute::FORM_BODY
                ? RequestContext::formOrJsonBody($request)
                : RequestContext::jsonBody($request);
            if ($body === null) {
                return $this->json->result(ApiError::invalidJson()->toArray());
            }

            $request = $request->withAttribute(RequestContext::ATTR_BODY, $body);
        }

        $result = $route->invoke($match['parameters'], $request);
        if ($result instanceof PromiseInterface) {
            return $result->then(fn(mixed $value): Response => $this->routeResponse($value, $route->status()));
        }

        return $this->routeResponse($result, $route->status());
    }

    /** Um handler devolve a resposta feita ou o resultado do serviço em cru; o embrulho é aqui. */
    private function routeResponse(mixed $result, int $status): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return $this->json->result($result, $status);
        }

        throw new \RuntimeException('API route handler returned neither an array nor a response.');
    }

    private function isPublicApiPath(string $path): bool
    {
        return in_array($path, [
            '/api/auth/login',
            '/api/auth/logout',
            '/api/docs',
            '/api/openapi.json',
        ], true);
    }

    // O `ETag` sai do corpo já serializado: um contador por tabela exigia escrituração em
    // cada escrita, e é isso que envelhece mal. Fica no kernel, e não num middleware, porque
    // é a resposta desta rota que decide -- não o pedido.
    private function revalidatedCatalogResponse(
        ServerRequestInterface $request,
        Response $response,
        string $method,
        ?string $routePattern
    ): Response {
        if (
            $method !== 'GET'
            || $response->getStatusCode() !== 200
            || $routePattern === null
            || !in_array($routePattern, self::REVALIDATED_ROUTES, true)
        ) {
            return $response;
        }

        $body = $response->getBody();
        if (!$body->isSeekable()) {
            return $response;
        }

        $position = $body->tell();
        $body->rewind();
        $contents = $body->getContents();
        $body->seek($position);

        $etag = '"' . md5($contents) . '"';
        $response = $response->withHeader('ETag', $etag)->withHeader('Cache-Control', 'no-cache');
        if ($request->getHeaderLine('If-None-Match') !== $etag) {
            return $response;
        }

        return new Response(304, $response->withoutHeader('Content-Type')->getHeaders());
    }
}
