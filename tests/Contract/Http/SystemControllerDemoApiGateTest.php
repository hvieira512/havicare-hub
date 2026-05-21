<?php

declare(strict_types=1);

namespace Tests\Contract\Http;

use App\Http\Controller\SystemController;
use App\Services\EventService;
use App\Services\ServiceException;
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

    public function testSimulateDeviceEventReturnsServiceErrorWhenPersistenceFails(): void
    {
        putenv('DEMO_API_ENABLED=true');

        $eventService = new class () extends EventService {
            public function __construct()
            {
                parent::__construct(null, null);
            }

            public function simulateDeviceEvent(?\PDO $pdo, ?\App\WebSocket\WatchServer $watchServer, array $body): array
            {
                throw new ServiceException('event_persist_failed', 'Failed to persist simulated event', 500, ['cause' => 'boom']);
            }
        };

        $controller = new SystemController(
            watchServer: null,
            pdo: null,
            redis: null,
            wsServerUrl: null,
            systemService: null,
            eventService: $eventService,
        );

        $request = new ServerRequest(
            'POST',
            '/demo/simulate',
            ['Content-Type' => 'application/json'],
            json_encode(['imei' => '865028000000307', 'type' => 'upBattery', 'data' => ['battery' => 90]])
        );

        $response = $controller->simulateDeviceEvent($request);
        $body = (string)$response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('"code":"event_persist_failed"', $body);
        self::assertStringContainsString('"cause":"boom"', $body);
    }
}
