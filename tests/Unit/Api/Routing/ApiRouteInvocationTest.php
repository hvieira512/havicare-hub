<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Routing;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Routing\ApiRoute;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Como uma rota chama o seu controlador.
 *
 * O contrato era implícito: o `ApiKernel` construía uma `ReflectionMethod` **a cada pedido**
 * para adivinhar, pela contagem de parâmetros e pelo tipo do primeiro, se o controlador queria
 * o pedido, os parâmetros do caminho, ou os dois. Isso custava reflexão no caminho quente --
 * o mesmo processo que serve a ingestão TCP de todos os relógios -- e, pior, não estava
 * escrito em lado nenhum: um controlador com os dois argumentos trocados compilava, passava o
 * PHPStan e falhava em execução.
 *
 * As rotas são construídas uma vez no arranque. A forma da chamada resolve-se aí.
 */
final class ApiRouteInvocationTest extends TestCase
{
    public function testAHandlerWithoutArgumentsIsCalledWithNone(): void
    {
        $route = new ApiRoute('GET', '/api/thing', static fn(): string => 'sem argumentos');

        self::assertSame('sem argumentos', $route->invoke([], self::request()));
    }

    public function testAHandlerAskingForTheRequestGetsTheRequest(): void
    {
        $route = new ApiRoute(
            'GET',
            '/api/thing',
            static fn(ServerRequestInterface $request): string => $request->getUri()->getPath(),
        );

        self::assertSame('/api/devices/42', $route->invoke([], self::request('/api/devices/42')));
    }

    public function testAHandlerAskingForOneArrayGetsThePathParameters(): void
    {
        $route = new ApiRoute('GET', '/api/things/{imei}', static fn(array $params): string => $params['imei']);

        self::assertSame('865028000000306', $route->invoke(['imei' => '865028000000306'], self::request()));
    }

    public function testAHandlerWithTwoArgumentsGetsParametersThenRequest(): void
    {
        $route = new ApiRoute(
            'GET',
            '/api/things/{imei}',
            static fn(array $params, ServerRequestInterface $request): string
                => $params['imei'] . '@' . $request->getMethod(),
        );

        self::assertSame('123@GET', $route->invoke(['imei' => '123'], self::request()));
    }

    /**
     * A reflexão corre uma vez por rota e não uma vez por pedido.
     *
     * Sem isto o ganho não existe: a forma continuaria a ser recalculada a cada chamada, que
     * é exactamente o que estava a acontecer.
     */
    public function testTheInvocationShapeIsResolvedOncePerRoute(): void
    {
        $calls = 0;
        $route = new ApiRoute('GET', '/api/thing', static function (ServerRequestInterface $request) use (&$calls): int {
            $calls++;
            return $calls;
        });

        $before = self::reflectionCount();
        for ($i = 0; $i < 50; $i++) {
            $route->invoke([], self::request());
        }

        self::assertSame(50, $calls);
        self::assertSame(
            $before,
            self::reflectionCount(),
            'a forma da chamada tem de ficar resolvida na rota, e não ser reconstruída a cada pedido',
        );
    }

    /** Quantas vezes a rota olhou para a assinatura do seu handler. */
    private static function reflectionCount(): int
    {
        $property = new \ReflectionProperty(ApiRoute::class, 'shapeResolutions');

        return (int)$property->getValue();
    }

    private static function request(string $path = '/api/thing'): ServerRequest
    {
        return new ServerRequest('GET', 'http://localhost:8081' . $path);
    }
}
