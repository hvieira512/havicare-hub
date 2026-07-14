<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer;
use Hub\Ingress\Mqtt\Qinglanst\Topic;
use PHPUnit\Framework\TestCase;

final class MessageNormalizerTest extends TestCase
{
    public function testNormalizesHeartBreathTelemetryUsingTopicUidAndNativeType(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'heartbreath',
            'device_code' => 'radar-topic-uid',
            'breathing' => 12,
            'heart_rate' => 72,
            'sleep_state' => 'Light Sleep',
        ], $topic, $this->device());

        self::assertSame('vitals', $result['telemetry']['type']);
        self::assertSame('radar-topic-uid', $result['telemetry']['device']['id']);
        self::assertSame('Qinglanst RD-V1 Pro', $result['telemetry']['device']['commercialName']);
        self::assertSame('heartbreath', $result['telemetry']['source']['nativeType']);
    }

    public function testNormalizesPosStaticsTelemetryUsingRawNativeType(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'posstatics',
            'device_code' => 'radar-topic-uid',
            'version' => 2,
            'people' => 1,
            'walking_distance' => 42,
            'walking_time' => 4,
            'meditation_time' => 5,
            'in_bed_time' => 6,
            'standing_time' => 7,
            'multiplayer_time' => 8,
            'breathing_active' => true,
        ], $topic, $this->device());

        self::assertSame('minute_stats', $result['telemetry']['type']);
        self::assertSame('posstatics', $result['telemetry']['source']['nativeType']);
        self::assertSame(42, $result['telemetry']['data']['walking_distance']);
    }

    public function testPositionDetectionIncludesDeviceAndSourceMetadata(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [[
                'person_index' => 1,
                'x_position_dm' => 1,
                'y_position_dm' => 2,
                'z_position_cm' => 3,
                'time_left_s' => 4,
                'posture_state' => 'Fall Confirmation',
                'last_event' => 'No Event',
                'region_id' => 5,
            ]],
        ], $topic, $this->device());

        self::assertSame('detection', $result['event']['type']);
        self::assertSame('radar-topic-uid', $result['event']['device']['id']);
        self::assertSame('position', $result['event']['source']['nativeType']);
        self::assertSame('fall_confirmed', $result['event']['data']['detectionType']);
    }

    public function testPositionNormalizationDropsSentinelPersonIndex88(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [[
                'person_index' => 88,
                'x_position_dm' => 0,
                'y_position_dm' => 0,
                'z_position_cm' => 0,
                'time_left_s' => 0,
                'posture_state' => 'Unknown',
                'last_event' => 'No Event',
                'region_id' => 0,
            ]],
        ], $topic, $this->device());

        self::assertSame([], $result['telemetry']['data']['people']);
        self::assertArrayNotHasKey('event', $result);
    }

    public function testPositionNormalizationKeepsRealPeopleWhenSentinelIsPresent(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [
                [
                    'person_index' => 88,
                    'x_position_dm' => 0,
                    'y_position_dm' => 0,
                    'z_position_cm' => 0,
                    'time_left_s' => 0,
                    'posture_state' => 'Unknown',
                    'last_event' => 'No Event',
                    'region_id' => 0,
                ],
                [
                    'person_index' => 2,
                    'x_position_dm' => 4,
                    'y_position_dm' => 5,
                    'z_position_cm' => 6,
                    'time_left_s' => 7,
                    'posture_state' => 'Fall Confirmation',
                    'last_event' => 'Leave Room',
                    'region_id' => 8,
                ],
            ],
        ], $topic, $this->device());

        self::assertCount(1, $result['telemetry']['data']['people']);
        self::assertSame(2, $result['telemetry']['data']['people'][0]['person_index']);
        self::assertSame('detection', $result['event']['type']);
        self::assertSame('fall_confirmed', $result['event']['data']['detectionType']);
        self::assertSame(2, $result['event']['data']['details']['person_index']);
    }

    /**
     * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, company: string}
     */
    private function device(): array
    {
        return [
            'imei' => 'canonical-radar-id',
            'supplier' => 'Qinglanst',
            'model' => 'RD-V1',
            'commercialName' => 'Qinglanst RD-V1 Pro',
            'deviceType' => 'radar',
            'licenseId' => '1001',
            'company' => 'hitcare',
        ];
    }
}
