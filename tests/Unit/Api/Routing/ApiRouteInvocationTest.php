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

    /** O corpo e o estado de sucesso são declarados na rota, e é o kernel que lhes pega. */
    public function testARouteDeclaresWhetherItTakesABodyAndWithWhichStatus(): void
    {
        $plain = new ApiRoute('GET', '/api/things', static fn(): array => []);
        $created = new ApiRoute(
            'POST',
            '/api/things',
            static fn(): array => [],
            body: ApiRoute::JSON_BODY,
            status: 201,
        );

        self::assertNull($plain->body());
        self::assertSame(200, $plain->status());
        self::assertSame(ApiRoute::JSON_BODY, $created->body());
        self::assertSame(201, $created->status());
    }

    private static function request(string $path = '/api/thing'): ServerRequest
    {
        return new ServerRequest('GET', 'http://localhost:8081' . $path);
    }
}
