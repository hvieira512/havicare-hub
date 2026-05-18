<?php

declare(strict_types=1);

namespace Tests\Contract\Http;

use App\Http\Controller\SystemController;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

final class SystemControllerDemoApiGateTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('DEMO_API_ENABLED');
    }

    public function testSimulateDeviceEventReturnsNotFoundWhenDemoApiDisabled(): void
    {
        putenv('DEMO_API_ENABLED=false');

        $controller = new SystemController();
        $request = new ServerRequest(
            'POST',
            '/demo/simulate',
            ['Content-Type' => 'application/json'],
            json_encode(['imei' => '865028000000307', 'type' => 'upBattery', 'data' => ['battery' => 90]])
        );

        $response = $controller->simulateDeviceEvent($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('"code":"not_found"', (string)$response->getBody());
    }

    public function testSimulateDeviceEventReturnsMysqlUnavailableWhenDemoApiEnabledWithoutDb(): void
    {
        putenv('DEMO_API_ENABLED=true');

        $controller = new SystemController();
        $request = new ServerRequest(
            'POST',
            '/demo/simulate',
            ['Content-Type' => 'application/json'],
            json_encode(['imei' => '865028000000307', 'type' => 'upBattery', 'data' => ['battery' => 90]])
        );

        $response = $controller->simulateDeviceEvent($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('"code":"mysql_unavailable"', (string)$response->getBody());
    }
}
