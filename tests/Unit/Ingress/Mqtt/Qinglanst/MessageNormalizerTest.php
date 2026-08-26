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

        self::assertSame('vitals', $result['telemetry']['vitals']['type']);
        self::assertSame('radar-topic-uid', $result['telemetry']['vitals']['device']['id']);
        self::assertSame('Qinglanst RD-V1 Pro', $result['telemetry']['vitals']['device']['commercialName']);
        self::assertSame('heartbreath', $result['telemetry']['vitals']['source']['nativeType']);
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

        $telemetry = $result['telemetry']['position_minute_stats'];
        self::assertSame('minute_stats', $telemetry['type']);
        self::assertSame('posstatics', $telemetry['source']['nativeType']);
        self::assertSame(42, $telemetry['data']['walking_distance']);
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

        $event = $result['events'][0];
        self::assertSame('detection', $event['type']);
        self::assertSame('radar-topic-uid', $event['device']['id']);
        self::assertSame('position', $event['source']['nativeType']);
        self::assertSame('fall_confirmed', $event['data']['detectionType']);
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

        self::assertSame([], $result['telemetry']['positions']['data']['people']);
        self::assertSame([], $result['events']);
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

        $people = $result['telemetry']['positions']['data']['people'];
        self::assertCount(1, $people);
        self::assertSame(2, $people[0]['person_index']);
        self::assertSame('detection', $result['events'][0]['type']);
        self::assertSame('fall_confirmed', $result['events'][0]['data']['detectionType']);
        self::assertSame(2, $result['events'][0]['data']['details']['person_index']);
    }

    /**
     * Dois alarmes na mesma mensagem saem os dois.
     *
     * O normalizador juntava-os numa lista e depois fazia `$result['event'] = $events[0]`.
     * Uma apneia e uma bradicardia no mesmo minuto -- que é precisamente quando alguém está
     * pior, não melhor -- e o segundo desaparecia sem deixar rasto no log nem no Redis.
     */
    public function testEveryAlarmInOneMessageSurvives(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'hbstatics',
            'device_code' => 'radar-topic-uid',
            'real_time_breathing' => 0,
            'real_time_heart_rate' => 0,
            'avg_breathing_per_minute' => 0,
            'avg_heart_rate_per_minute' => 30,
            'breathing_status_per_minute' => 'Apnea',
            'heart_rate_status_per_minute' => 'Low',
            'vital_signs_status' => 'Weak',
            'sleep_state_status' => 'Undefined',
        ], $topic, $this->device());

        self::assertSame(
            ['apnea', 'heart_rate_low', 'vitals_signal_lost'],
            array_column(array_column($result['events'], 'data'), 'detectionType'),
        );
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
