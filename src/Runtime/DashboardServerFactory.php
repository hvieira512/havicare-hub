<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Api\Auth\ApiTokenStore;
use Hub\Dashboard\DashboardHttpServer;
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
            $services->downlinkQueue,
            $services->dataAccess,
            (bool)$dashboardConfig['api_auth_required'],
            (int)$dashboardConfig['api_token_ttl_seconds'],
            (int)$dashboardConfig['api_refresh_token_ttl_seconds'],
        );

        $server = new ReactHttpServer(
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware(self::MAX_CONCURRENT_REQUESTS),
            new RequestBodyBufferMiddleware(self::BODY_BUFFER_BYTES),
            new RequestBodyParserMiddleware(self::BODY_PARSE_BYTES),
            $dashboard,
        );

        $host = $dashboardConfig['host'];
        $port = $dashboardConfig['port'];
        $server->listen(new SocketServer("$host:$port", [], $loop));
    }
}
