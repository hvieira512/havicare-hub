<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EventService;
use App\WebSocket\WatchServer;
use PHPUnit\Framework\TestCase;

final class EventServiceTest extends TestCase
{
    public function testPersistWatchIngressEventFallsBackToLocalIncrementingId(): void
    {
        $service = new EventService(null, null);
        $nextId = 7;

        $stored = $service->persistWatchIngressEvent([
            'imei' => '865028000000306',
            'nativeType' => 'upBattery',
            'feature' => null,
            'nativePayload' => ['battery' => 90],
            'receivedAt' => 1710000000,
        ], $nextId);

        self::assertSame(7, $stored['id']);
        self::assertSame(8, $nextId);
    }

    public function testIngestInMemoryMaintainsHistoryBuffer(): void
    {
        $service = new EventService(null, null);
        $deviceData = [];
        $history = [];

        $service->ingestInMemory(['id' => 1, 'imei' => 'A'], $deviceData, $history, 1);
        $service->ingestInMemory(['id' => 2, 'imei' => 'B'], $deviceData, $history, 1);

        self::assertCount(1, $history);
        self::assertSame(2, $history[0]['id']);
        self::assertSame('B', $deviceData['B']['imei']);
    }

    public function testLatestDeviceFeatureEventExtractsFromMixedPayload(): void
    {
        $watchServer = $this->getMockBuilder(WatchServer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRecentEvents'])
            ->getMock();

        $watchServer->expects(self::once())
            ->method('getRecentEvents')
            ->with(250, null)
            ->willReturn([
                [
                    'id' => 10,
                    'imei' => '865028000000308',
                    'nativeType' => 'APHP',
                    'feature' => 'heart_rate',
                    'nativePayload' => [
                        'systolic' => 130,
                        'diastolic' => 85,
                    ],
                    'receivedAt' => 1710000000000,
                ],
            ]);

        $service = new EventService(null, null);
        $event = $service->latestDeviceFeatureEvent($watchServer, '865028000000308', 'blood_pressure');

        self::assertIsArray($event);
        self::assertSame('APHP', $event['nativeType']);
        self::assertArrayHasKey('featureNormalizedData', $event);
        self::assertSame(130, $event['featureNormalizedData']['systolicMmHg'] ?? null);
        self::assertSame(85, $event['featureNormalizedData']['diastolicMmHg'] ?? null);
    }

    public function testWaitLatestDeviceFeatureEventReturnsWhenFreshEventArrives(): void
    {
        $watchServer = $this->getMockBuilder(WatchServer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRecentEvents'])
            ->getMock();

        $watchServer->expects(self::exactly(2))
            ->method('getRecentEvents')
            ->with(250, null)
            ->willReturnOnConsecutiveCalls(
                [[
                    'id' => 10,
                    'imei' => '865028000000308',
                    'nativeType' => 'AP49',
                    'feature' => 'heart_rate',
                    'nativePayload' => ['heartRate' => 70],
                    'receivedAt' => 1710000000000,
                ]],
                [[
                    'id' => 11,
                    'imei' => '865028000000308',
                    'nativeType' => 'AP49',
                    'feature' => 'heart_rate',
                    'nativePayload' => ['heartRate' => 72],
                    'receivedAt' => 1710000003000,
                ]]
            );

        $service = new EventService(null, null);
        $event = $service->waitLatestDeviceFeatureEvent(
            $watchServer,
            '865028000000308',
            'heart_rate',
            1710000001000,
            5000,
            100
        );

        self::assertIsArray($event);
        self::assertSame(11, $event['id']);
    }
}
