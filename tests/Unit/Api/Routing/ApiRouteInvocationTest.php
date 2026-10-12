<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Routing;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Routing\ApiRoute;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Uma rota chama o handler com os parâmetros do caminho e depois o pedido, e quem não quer os
 * argumentos declara menos. Trocá-los só falharia em execução.
 */
final class ApiRouteInvocationTest extends TestCase
{
    public function testTheHandlerGetsTheParametersAndThenTheRequest(): void
    {
        $route = new ApiRoute(
            'GET',
            '/api/things/{imei}',
            static fn(array $params, ServerRequestInterface $request): string
                => $params['imei'] . '@' . $request->getMethod(),
        );

        self::assertSame('123@GET', $route->invoke(['imei' => '123'], self::request()));
    }

    public function testAHandlerThatDeclaresFewerArgumentsIgnoresTheRest(): void
    {
        $route = new ApiRoute('GET', '/api/things/{imei}', static fn(array $params): string => $params['imei']);

        self::assertSame('865028000000306', $route->invoke(['imei' => '865028000000306'], self::request()));
    }

    private static function request(string $path = '/api/thing'): ServerRequest
    {
        return new ServerRequest('GET', 'http://localhost:8081' . $path);
    }
}
