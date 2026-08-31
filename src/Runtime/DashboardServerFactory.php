<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Http\CorsPolicy;
use Hub\Api\Http\Middleware\ApiRequestLogger;
use Hub\Api\Http\Middleware\CorsMiddleware;
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
     * @param array<string, mixed> $dashboardConfig the `dashboard` section of the hub config
     */
    public static function listen(HubServices $services, array $dashboardConfig, LoopInterface $loop): void
    {
        $dashboard = new DashboardHttpServer(
            $services->dashboardStore,
            new ApiTokenStore($services->redis),
            $services->whitelist,
            $services->hubServer,
            $services->dataAccess,
            (bool)$dashboardConfig['api_auth_required'],
            (int)$dashboardConfig['api_token_ttl_seconds'],
            (int)$dashboardConfig['api_refresh_token_ttl_seconds'],
        );
        // O construtor já não escreve no Redis: quem serve é que semeia, e só aqui.
        $dashboard->warmUp();

        $server = new ReactHttpServer(
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware(self::MAX_CONCURRENT_REQUESTS),
            new RequestBodyBufferMiddleware(self::BODY_BUFFER_BYTES),
            new RequestBodyParserMiddleware(self::BODY_PARSE_BYTES),
            self::handler($dashboard),
        );

        $host = $dashboardConfig['host'];
        $port = $dashboardConfig['port'];
        $server->listen(new SocketServer("$host:$port", [], $loop));
    }

    /**
     * O CORS e o registo do `/api/` são middleware do ReactPHP como os de cima, mas vêm
     * dobrados num só manipulador em vez de espalhados pela lista: assim os testes chamam
     * isto e exercitam exactamente a cadeia que corre em produção, sem a repetir.
     *
     * A ordem importa: o CORS responde ao preflight e devolve sem descer, e é por isso que o
     * `OPTIONS` nunca chegou -- nem chega -- ao canal `api`.
     */
    public static function handler(DashboardHttpServer $dashboard): callable
    {
        $cors = new CorsMiddleware(new CorsPolicy());
        $log = new ApiRequestLogger();

        return static fn(ServerRequestInterface $request): mixed => $cors(
            $request,
            static fn(ServerRequestInterface $inner): mixed => $log($inner, $dashboard)
        );
    }
}
