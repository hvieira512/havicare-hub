<?php

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\ApiRoute;
use Hub\Dashboard\ApiRouter;
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
}
