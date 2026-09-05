<?php

namespace Tests\Unit\Dashboard;

use Hub\Api\Routing\ApiRoute;
use Hub\Api\Routing\ApiRouter;
use PHPUnit\Framework\TestCase;

final class ApiRouterTest extends TestCase
{
    public function testMatchesStaticAndParameterizedRoutes(): void
    {
        $router = new ApiRouter([
            new ApiRoute('GET', '/api/devices', static fn(): string => 'devices'),
            new ApiRoute('GET', '/api/devices/{imei}', static fn(): string => 'device'),
            new ApiRoute('PUT', '/api/models/{id:\d+}', static fn(): string => 'model'),
        ]);

        $devices = $router->match('GET', '/api/devices');
        self::assertNotNull($devices);
        self::assertSame([], $devices['parameters']);

        $device = $router->match('GET', '/api/devices/865028000000308');
        self::assertNotNull($device);
        self::assertSame(['imei' => '865028000000308'], $device['parameters']);

        $model = $router->match('PUT', '/api/models/42');
        self::assertNotNull($model);
        self::assertSame(['id' => '42'], $model['parameters']);
    }

    public function testRejectsInvalidMethodAndConstraintMismatch(): void
    {
        $router = new ApiRouter([
            new ApiRoute('DELETE', '/api/suppliers/{id:\d+}', static fn(): string => 'supplier'),
        ]);

        self::assertNull($router->match('GET', '/api/suppliers/10'));
        self::assertNull($router->match('DELETE', '/api/suppliers/abc'));
    }

    /**
     * O HTTP exige que um recurso que aceita GET aceita HEAD. As rotas do hub são todas GET,
     * e um HEAD -- de um health check ou de uma sonda que confirma a existência antes de
     * descarregar -- casa a rota GET equivalente em vez de dar 404.
     */
    public function testHeadFallsBackToTheGetRoute(): void
    {
        $router = new ApiRouter([
            new ApiRoute('GET', '/api/devices/{imei}', static fn(): string => 'device'),
        ]);

        $match = $router->match('HEAD', '/api/devices/865028000000308');
        self::assertNotNull($match);
        self::assertSame(['imei' => '865028000000308'], $match['parameters']);
    }

    /** Um HEAD explícito ganha ao fallback: quem declarar a rota HEAD é ela que responde. */
    public function testAnExplicitHeadRouteWins(): void
    {
        $router = new ApiRouter([
            new ApiRoute('GET', '/api/thing', static fn(): string => 'get'),
            new ApiRoute('HEAD', '/api/thing', static fn(): string => 'head'),
        ]);

        $match = $router->match('HEAD', '/api/thing');
        self::assertNotNull($match);
        self::assertSame('head', ($match['route']->handler())([], null));
    }

    /** O fallback é só de HEAD para GET: um POST em falta continua sem rota. */
    public function testHeadFallbackDoesNotLeakToOtherMethods(): void
    {
        $router = new ApiRouter([
            new ApiRoute('GET', '/api/thing', static fn(): string => 'get'),
        ]);

        self::assertNull($router->match('POST', '/api/thing'));
        self::assertNull($router->match('HEAD', '/api/missing'));
    }
}
