<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repository\EventRepository;
use App\Services\EventService;
use PHPUnit\Framework\TestCase;

final class EventServiceSimulateResponseTest extends TestCase
{
    public function testSimulateDeviceEventIncludesFeaturePayloadDataAndExtra(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE devices (imei TEXT PRIMARY KEY)');
        $pdo->exec("INSERT INTO devices (imei) VALUES ('865028000000307')");

        $repo = $this->createMock(EventRepository::class);
        $repo->expects(self::once())
            ->method('insert')
            ->willReturn(101);

        $service = new EventService($repo, null);

        $response = $service->simulateDeviceEvent($pdo, null, [
            'imei' => '865028000000307',
            'type' => 'upHeartRate',
            'model' => 'WONLEX-HEALTH',
            'data' => ['heartRate' => 72],
        ]);

        self::assertSame('simulated', $response['data']['status'] ?? null);
        self::assertSame('heart_rate', $response['data']['feature'] ?? null);

        $featurePayload = $response['data']['featurePayload'] ?? null;
        self::assertIsArray($featurePayload);
        self::assertSame('heart_rate', $featurePayload['feature'] ?? null);
        self::assertSame(72, $featurePayload['data']['bpm'] ?? null);
        self::assertArrayHasKey('extra', $featurePayload);
    }
}
