<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Auth\LoginThrottle;
use Hub\Api\Http\CorsPolicy;
use Hub\Api\Http\Middleware\ApiRequestLogger;
use Hub\Api\Http\Middleware\CorsMiddleware;
use Hub\Dashboard\DashboardHttpOptions;
use Hub\Dashboard\DashboardHttpServer;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\SocketServer;
use React\EventLoop\LoopInterface;

final class DashboardServerFactory
{
    private const MAX_CONCURRENT_REQUESTS = 50;
    private const BODY_BUFFER_BYTES = 6 * 1024 * 1024;
    private const BODY_PARSE_BYTES = 5 * 1024 * 1024;

    /**
     * @param array<string, mixed> $dashboardConfig a secção `dashboard` da configuração do hub
     */
    public static function listen(HubServices $services, array $dashboardConfig, LoopInterface $loop): void
    {
        $dashboard = new DashboardHttpServer(
            $services->deviceStore,
            new ApiTokenStore($services->redis),
            $services->whitelist,
            $services->hubServer,
            $services->dataAccess,
            DashboardHttpOptions::fromConfig($dashboardConfig),
            // O fan-out vem do bridge de propósito: é o mesmo objecto que a ingestão usa para
            // publicar, e por isso não há duas instâncias possíveis.
            $services->mqttBridge->messages(),
            new LoginThrottle(
                $services->redis,
                maxPerAddress: (int)$dashboardConfig['login_max_per_address'],
                maxPerUsername: (int)$dashboardConfig['login_max_per_username'],
                maxGlobal: (int)$dashboardConfig['login_max_global'],
            ),
            $services->radarLayoutSync,
        );
        // Quem serve é que semeia o Redis, e só aqui.
        $dashboard->warmUp();

        $server = new ReactHttpServer(
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware(self::MAX_CONCURRENT_REQUESTS),
            new RequestBodyBufferMiddleware(self::BODY_BUFFER_BYTES),
            new RequestBodyParserMiddleware(self::BODY_PARSE_BYTES),
            self::handler($dashboard, $dashboardConfig['cors_allowed_origins'] ?? []),
        );

        $host = $dashboardConfig['host'];
        $port = $dashboardConfig['port'];
        $server->listen(new SocketServer("$host:$port", [], $loop));
    }

    /**
     * O CORS e o registo do `/api/` dobrados num só manipulador, para os testes exercitarem a
     * cadeia de produção. O CORS responde ao preflight sem descer, e o `OPTIONS` não chega ao `api`.
     *
     * @param list<string> $allowedOrigins vazio mantém a política aberta
     */
    public static function handler(DashboardHttpServer $dashboard, array $allowedOrigins = []): callable
    {
        $cors = new CorsMiddleware(new CorsPolicy($allowedOrigins));
        $log = new ApiRequestLogger();

        return static fn(ServerRequestInterface $request): mixed => $cors(
            $request,
            static fn(ServerRequestInterface $inner): mixed => $log($inner, $dashboard)
        );
    }
}
