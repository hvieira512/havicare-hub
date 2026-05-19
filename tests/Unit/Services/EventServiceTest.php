<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EventService;
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
}
