<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\EventNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventNormalizerTest extends TestCase
{
    #[DataProvider('featureCases')]
    public function testNormalizeFeaturePayloads(?string $feature, ?string $nativeType, array $payload, array $expected): void
    {
        self::assertSame($expected, EventNormalizer::normalize($feature, $nativeType, $payload));
    }

    public static function featureCases(): array
    {
        return [
            'heart rate' => ['heart_rate', 'upHeartRate', ['heartRate' => 72], ['heartRateBpm' => 72]],
            'blood pressure' => ['blood_pressure', 'upBP', ['date' => '120/80/70'], ['systolicMmHg' => 120, 'diastolicMmHg' => 80, 'pulseBpm' => 70]],
            'oxygen' => ['blood_oxygen', 'upBO', ['spo2' => 97], ['spo2Percent' => 97]],
            'blood fat' => ['blood_fat', 'upBF', ['date' => '3.12/1.20/1.10/3.12'], ['totalCholesterol' => 3.12, 'hdl' => 1.2, 'ldl' => 1.1, 'triglycerides' => 3.12]],
            'temperature' => ['temperature', 'AP50', ['temperature' => 36.6], ['bodyTemperatureC' => 36.6]],
            'battery' => ['battery', 'upBattery', ['batteryLevel' => 85, 'batteryState' => 1], ['batteryPercent' => 85, 'chargingState' => 1]],
            'location' => ['location', 'upLocation', ['lat' => '41.15', 'lng' => '-8.61'], ['latitude' => 41.15, 'longitude' => -8.61, 'source' => 'gps']],
            'vivistar ap02 location' => ['location', 'AP02', [
                'fields' => [
                    'zh_cn',
                    '0',
                    '1',
                    '268',
                    '6',
                    '8820|1624085|22',
                    '4',
                    'a|dc-fe-23-b8-b6-9b|88&a|dc-fe-23-36-57-4d|84',
                ],
            ], [
                'source' => 'lbs_wifi',
                'lac' => 8820,
                'cellId' => 1624085,
                'baseStationSignal' => 22,
                'mcc' => 268,
                'mnc' => 6,
                'wifiCount' => 2,
                'wifiAccessPoints' => [
                    ['mac' => 'dc-fe-23-b8-b6-9b', 'rssi' => 88],
                    ['mac' => 'dc-fe-23-36-57-4d', 'rssi' => 84],
                ],
                'baseStationCount' => 1,
            ]],
            'activity' => ['activity', 'upBatch', ['steps' => 1200, 'distance' => 800.5], ['steps' => 1200, 'distanceMeters' => 800.5]],
            'activity fallback from mileage' => ['activity', 'upRun', ['mileage' => '2.8'], ['steps' => 3640, 'distanceMeters' => 2.8]],
            'rr intervals' => ['rr_interval', 'upRR', ['data' => '1648111390074,1080;1648111391154,1570', 'frequency' => '100'], [
                'intervalMs' => 1080,
                'series' => [
                    ['timestamp' => 1648111390074, 'intervalMs' => 1080],
                    ['timestamp' => 1648111391154, 'intervalMs' => 1570],
                ],
                'sampleFrequencyHz' => 100,
            ]],
            'sleep legacy value' => ['sleep', 'upSleep', ['value' => '420/120/280/20'], ['durationMinutes' => 420, 'deepMinutes' => 120, 'lightMinutes' => 280, 'awakeMinutes' => 20]],
            'messaging audio kind' => ['messaging', 'AP07', ['sequence' => 1, 'audio' => 'base64'], ['kind' => 'audio_message', 'audio' => ['sequence' => 1, 'audio' => 'base64']]],
            'messaging call log kind' => ['messaging', 'upCallLog', ['phone' => '+3519', 'duration' => 30, 'direction' => 1], ['kind' => 'call_log', 'phone' => '+3519', 'callLog' => ['phone' => '+3519', 'duration' => 30, 'callType' => 1]]],
            'generic apht fallback' => [null, 'APHT', ['date' => '120/80/95'], ['systolicMmHg' => 120, 'diastolicMmHg' => 80, 'pulseBpm' => 95, 'spo2Percent' => 120]],
            'generic fallback text' => [null, 'X', ['value' => 'sensor error'], ['text' => 'sensor error']],
        ];
    }

    public function testLocationVivistarNormalizerCanBeAppliedByExplicitProtocol(): void
    {
        $payload = [
            'fields' => [
                'zh_cn',
                '0',
                '1',
                '268',
                '6',
                '8820|1624085|22',
                '2',
                'a|dc-fe-23-b8-b6-9b|88&a|dc-fe-23-36-57-4d|84',
            ],
        ];

        $normalized = EventNormalizer::normalize('location', 'AP02', $payload, 'vivistar-iw');
        self::assertSame('lbs_wifi', $normalized['source'] ?? null);
        self::assertSame(268, $normalized['mcc'] ?? null);
        self::assertSame(6, $normalized['mnc'] ?? null);
        self::assertSame(2, $normalized['wifiCount'] ?? null);
    }

    public function testLocationVivistarNormalizerDoesNotApplyForDifferentExplicitProtocol(): void
    {
        $payload = [
            'fields' => [
                'zh_cn',
                '0',
                '1',
                '268',
                '6',
                '8820|1624085|22',
                '2',
                'a|dc-fe-23-b8-b6-9b|88',
            ],
        ];

        self::assertSame([], EventNormalizer::normalize('location', 'AP02', $payload, 'wonlex-json'));
    }
}
