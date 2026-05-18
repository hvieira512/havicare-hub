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
            'temperature' => ['temperature', 'AP50', ['temperature' => 36.6], ['bodyTemperatureC' => 36.6]],
            'battery' => ['battery', 'upBattery', ['batteryLevel' => 85, 'batteryState' => 1], ['batteryPercent' => 85, 'chargingState' => 1]],
            'location' => ['location', 'upLocation', ['lat' => '41.15', 'lng' => '-8.61'], ['latitude' => 41.15, 'longitude' => -8.61]],
            'activity' => ['activity', 'upBatch', ['steps' => 1200, 'distance' => 800.5], ['steps' => 1200, 'distanceMeters' => 800.5]],
            'generic apht fallback' => [null, 'APHT', ['date' => '120/80/95'], ['systolicMmHg' => 120, 'diastolicMmHg' => 80, 'pulseBpm' => 95, 'spo2Percent' => 120]],
            'generic fallback text' => [null, 'X', ['value' => 'sensor error'], ['text' => 'sensor error']],
        ];
    }
}
