<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\FeaturePayloadFormatter;
use PHPUnit\Framework\TestCase;

final class FeaturePayloadFormatterTest extends TestCase
{
    public function testFormatsHeartRatePayloadWithCanonicalDataAndExtra(): void
    {
        $payload = FeaturePayloadFormatter::format('heart_rate', [
            'imei' => '865028000000308',
            'nativeType' => 'AP49',
            'receivedAt' => 1710000000123,
            'featureNormalizedData' => [
                'heartRateBpm' => 72,
            ],
        ]);

        self::assertSame('865028000000308', $payload['imei']);
        self::assertSame('heart_rate', $payload['feature']);
        self::assertSame(72, $payload['data']['bpm'] ?? null);
        self::assertArrayHasKey('extra', $payload);
        self::assertNotNull($payload['timestamp']);
    }

    public function testFormatsLocationPayloadWithLatLonAliases(): void
    {
        $payload = FeaturePayloadFormatter::format('location', [
            'imei' => '865028000000307',
            'nativeType' => 'upLocation',
            'receivedAt' => 1710000000456,
            'featureNormalizedData' => [
                'latitude' => 38.7223,
                'longitude' => -9.1393,
                'satelliteCount' => 8,
                'source' => 'gps',
            ],
        ]);

        self::assertSame(38.7223, $payload['data']['lat'] ?? null);
        self::assertSame(-9.1393, $payload['data']['lon'] ?? null);
        self::assertSame('gps', $payload['data']['source'] ?? null);
        self::assertSame(8, $payload['extra']['satelliteCount'] ?? null);
    }

    public function testPreservesUnmappedNativePayloadInsideExtra(): void
    {
        $payload = FeaturePayloadFormatter::format('weather', [
            'imei' => '865028000000306',
            'nativeType' => 'upWeather',
            'receivedAt' => 1710000000789,
            'nativePayload' => [
                'weather' => 'Sunny',
                'temperature' => '24',
                'humidity' => '55',
            ],
        ]);

        self::assertSame('ok', $payload['data']['status'] ?? null);
        self::assertSame('Sunny', $payload['extra']['weather'] ?? null);
        self::assertSame('24', $payload['extra']['temperature'] ?? null);
        self::assertSame('55', $payload['extra']['humidity'] ?? null);
    }

    public function testHeartbeatAliasesMapVivistarAp03Shape(): void
    {
        $payload = FeaturePayloadFormatter::format('heartbeat', [
            'imei' => '865028000000309',
            'nativeType' => 'AP03',
            'receivedAt' => 1710000000789,
            'nativePayload' => [
                'steps' => 2450,
                'rollsFrequency' => 30,
                'batteryLevel' => 78,
                'satellites' => '006',
                'gsmSignal' => '040',
                'fortification' => '01',
                'workingMode' => '02',
            ],
        ]);

        self::assertSame('ok', $payload['data']['status'] ?? null);
        self::assertSame(2450, $payload['extra']['steps'] ?? null);
        self::assertSame(30, $payload['extra']['rollFrequency'] ?? null);
        self::assertSame(78, $payload['extra']['batteryPercent'] ?? null);
        self::assertSame(6, $payload['extra']['satelliteCount'] ?? null);
        self::assertSame(1, $payload['extra']['fortificationState'] ?? null);
        self::assertSame(2, $payload['extra']['workMode'] ?? null);
    }

    public function testEcgMapsSampleCountAndCanonicalExtraFields(): void
    {
        $payload = FeaturePayloadFormatter::format('ecg', [
            'imei' => '865028000000307',
            'nativeType' => 'upECG',
            'receivedAt' => 1710000000999,
            'nativePayload' => [
                'date' => '99.4,90.7,126.1',
                'frequency' => '500',
                'collectionLogo' => '59353675',
            ],
            'featureNormalizedData' => [
                'values' => [99.4, 90.7, 126.1],
            ],
        ]);

        self::assertSame(3, $payload['data']['sampleCount'] ?? null);
        self::assertSame('59353675', $payload['extra']['fileId'] ?? null);
        self::assertSame([99.4, 90.7, 126.1], $payload['extra']['waveform'] ?? null);
        self::assertSame(500, $payload['extra']['sampleFrequencyHz'] ?? null);
    }

    public function testPpgMapsSampleCountAndWaveform(): void
    {
        $payload = FeaturePayloadFormatter::format('ppg', [
            'imei' => '865028000000307',
            'nativeType' => 'upPPG',
            'receivedAt' => 1710000001000,
            'nativePayload' => [
                'date' => '110.2,120.6,99.7',
                'frequency' => '100',
                'collectionLogo' => '87654321',
            ],
        ]);

        self::assertSame(3, $payload['data']['sampleCount'] ?? null);
        self::assertSame([110.2, 120.6, 99.7], $payload['extra']['waveform'] ?? null);
        self::assertSame(100, $payload['extra']['sampleFrequencyHz'] ?? null);
        self::assertSame('87654321', $payload['extra']['collectionId'] ?? null);
    }

    public function testMessagingMapsKindAndTextFields(): void
    {
        $payload = FeaturePayloadFormatter::format('messaging', [
            'imei' => '865028000000307',
            'nativeType' => 'upSMS',
            'receivedAt' => 1710000001001,
            'nativePayload' => [
                'sender' => '+351900000001',
                'phone' => '+351900000002',
                'content' => 'saldo',
            ],
        ]);

        self::assertSame('sms', $payload['data']['kind'] ?? null);
        self::assertSame('+351900000001', $payload['extra']['sender'] ?? null);
        self::assertSame('+351900000002', $payload['extra']['phone'] ?? null);
        self::assertSame('saldo', $payload['extra']['text'] ?? null);
    }

    public function testSensorsFeatureMapsSingleSensorPacket(): void
    {
        $payload = FeaturePayloadFormatter::format('sensors', [
            'imei' => '865028000000306',
            'nativeType' => 'upSensorValue',
            'receivedAt' => 1710000001002,
            'nativePayload' => [
                'sensorType' => 0,
                'date' => '0.12,0.24,0.08',
            ],
        ]);

        self::assertSame('single', $payload['data']['kind'] ?? null);
        self::assertSame('0', $payload['extra']['sensorType'] ?? null);
        self::assertSame('0.12,0.24,0.08', $payload['extra']['value'] ?? null);
    }

    public function testEcgAnalysisPayloadStillExposesCanonicalSampleCount(): void
    {
        $payload = FeaturePayloadFormatter::format('ecg', [
            'imei' => '865028000000307',
            'nativeType' => 'upECGAnalysis',
            'receivedAt' => 1710000001003,
            'nativePayload' => [
                'fileBase64' => 'base64-ecg-analysis',
                'fileName' => 'N5AB2454.lp4',
                'dataStatus' => 0,
            ],
        ]);

        self::assertSame(1, $payload['data']['sampleCount'] ?? null);
        self::assertSame('base64-ecg-analysis', $payload['extra']['fileBase64'] ?? null);
    }

    public function testSleepFindPayloadUsesZeroTotalMinutesFallback(): void
    {
        $payload = FeaturePayloadFormatter::format('sleep', [
            'imei' => '865028000000307',
            'nativeType' => 'upSleepFind',
            'receivedAt' => 1710000001004,
            'nativePayload' => [
                'upDayStr' => '2026-05-13',
            ],
        ]);

        self::assertSame(0, $payload['data']['totalMinutes'] ?? null);
    }
}
