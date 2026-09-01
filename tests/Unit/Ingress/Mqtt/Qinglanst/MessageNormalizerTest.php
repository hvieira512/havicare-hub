<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer;
use Hub\Ingress\Mqtt\Qinglanst\Topic;
use PHPUnit\Framework\TestCase;

final class MessageNormalizerTest extends TestCase
{
    /**
     * As formas são as do `FeatureNormalizer`, as mesmas de um relógio, e é isso que dá ao
     * radar os cartões que já existem.
     */
    public function testHeartBreathBecomesThreeCanonicalReadings(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'heartbreath',
            'device_code' => 'radar-topic-uid',
            'breathing' => 12,
            'heart_rate' => 72,
            'sleep_state' => 'light_sleep',
        ], $topic, $this->device());

        self::assertSame(
            ['heart_rate', 'breath_rate', 'sleep_state'],
            array_keys($result['telemetry']),
        );
        self::assertSame(['bpm' => 72], $result['telemetry']['heart_rate']['data']);
        self::assertSame(['breathsPerMinute' => 12], $result['telemetry']['breath_rate']['data']);
        self::assertSame(['state' => 'light_sleep'], $result['telemetry']['sleep_state']['data']);

        self::assertSame('canonical-radar-id', $result['telemetry']['heart_rate']['device']['id']);
        self::assertSame('Qinglanst RD-V1 Pro', $result['telemetry']['heart_rate']['device']['commercialName']);
        self::assertSame('heartbreath', $result['telemetry']['heart_rate']['source']['nativeType']);
    }

    /**
     * Um zero não é leitura: é o radar a dizer que não mediu ninguém, e "0 bpm" lê-se como um
     * coração parado. O `Undefined` do sono é o mesmo caso.
     */
    public function testAbsentMeasurementsDoNotBecomeReadings(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'heartbreath',
            'device_code' => 'radar-topic-uid',
            'breathing' => 0,
            'heart_rate' => 0,
            'sleep_state' => 'undefined',
        ], $topic, $this->device());

        self::assertSame([], $result['telemetry']);
        // Sem sinal nenhum é alarme, que é coisa diferente de leitura.
        self::assertSame('vitals_signal_lost', $result['events'][0]['data']['detectionType']);
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
                'posture_state' => 'fall_confirmation',
                'last_event' => 'no_event',
                'region_id' => 5,
            ]],
        ], $topic, $this->device());

        $event = $result['events'][0];
        self::assertSame('fall', $event['type']);
        self::assertSame('canonical-radar-id', $event['device']['id']);
        self::assertSame('position', $event['source']['nativeType']);
        self::assertSame('fall_confirmed', $event['data']['detectionType']);
    }

    /**
     * Cada detecção sai na capacidade a que pertence, com o tipo específico dentro: com as
     * quinze a saírem como `detection`, uma queda vista por um radar e um SOS de uma pulseira
     * não se conseguiam listar nem alertar pela mesma regra.
     */
    public function testDetectionsCarryTheCapabilityTheyBelongTo(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $vitals = $normalizer->normalize([
            'type' => 'hbstatics',
            'device_code' => 'radar-topic-uid',
            'real_time_breathing' => 0,
            'real_time_heart_rate' => 0,
            'avg_breathing_per_minute' => 0,
            'avg_heart_rate_per_minute' => 30,
            'breathing_status_per_minute' => 'apnea',
            'heart_rate_status_per_minute' => 'normal',
            'vital_signs_status' => 'normal',
            'sleep_state_status' => 'undefined',
        ], $topic, $this->device());

        self::assertSame('vitals_alarm', $vitals['events'][0]['type']);
        self::assertSame('apnea', $vitals['events'][0]['data']['detectionType']);

        $presence = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [$this->person(1, 'walking', 'leave_room')],
        ], $topic, $this->device());

        self::assertSame('presence_event', $presence['events'][0]['type']);
        self::assertSame('room_exit', $presence['events'][0]['data']['detectionType']);
    }

    /** A telemetria e as detecções vão na mesma versão: é o mesmo protocolo. */
    public function testTheEnvelopeCarriesNoSchemaVersion(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [$this->person(1, 'fall_confirmation')],
        ], $topic, $this->device());

        self::assertArrayNotHasKey('schemaVersion', $result['events'][0]);
        self::assertArrayNotHasKey('schemaVersion', $result['telemetry']['presence']);
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
                'last_event' => 'no_event',
                'region_id' => 0,
            ]],
        ], $topic, $this->device());

        self::assertSame(0, $result['telemetry']['presence']['data']['count']);
        self::assertSame([], $result['telemetry']['presence']['data']['people']);
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
                    'last_event' => 'no_event',
                    'region_id' => 0,
                ],
                [
                    'person_index' => 2,
                    'x_position_dm' => 4,
                    'y_position_dm' => 5,
                    'z_position_cm' => 6,
                    'time_left_s' => 7,
                    'posture_state' => 'fall_confirmation',
                    'last_event' => 'leave_room',
                    'region_id' => 8,
                ],
            ],
        ], $topic, $this->device());

        $presence = $result['telemetry']['presence']['data'];
        self::assertSame(1, $presence['count']);
        self::assertCount(1, $presence['people']);
        self::assertSame(2, $presence['people'][0]['personIndex']);
        self::assertSame('fall_confirmation', $presence['people'][0]['posture']);
        self::assertSame('leave_room', $presence['people'][0]['lastEvent']);

        self::assertSame('fall', $result['events'][0]['type']);
        self::assertSame('fall_confirmed', $result['events'][0]['data']['detectionType']);
        self::assertSame(2, $result['events'][0]['data']['details']['person_index']);
    }

    /**
     * A postura é da pessoa, como a posição: numa divisão com três, uma leitura do aparelho
     * com uma postura só obrigava a escolher entre elas.
     */
    public function testEveryPersonKeepsTheirOwnPosture(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [
                $this->person(1, 'standing'),
                $this->person(2, 'fall_confirmation'),
                $this->person(3, 'walking'),
            ],
        ], $topic, $this->device());

        $presence = $result['telemetry']['presence']['data'];
        self::assertSame(3, $presence['count']);
        self::assertSame(
            ['standing', 'fall_confirmation', 'walking'],
            array_column($presence['people'], 'posture'),
        );
        self::assertSame([1, 2, 3], array_column($presence['people'], 'personIndex'));

        // A queda continua a ser alarme: é isso que a põe à frente de quem olha, sem a
        // telemetria ter de escolher uma pessoa entre as presentes.
        self::assertSame('fall', $result['events'][0]['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function person(int $index, string $posture, string $lastEvent = 'no_event'): array
    {
        return [
            'person_index' => $index,
            'x_position_dm' => 1,
            'y_position_dm' => 2,
            'z_position_cm' => 3,
            'time_left_s' => 4,
            'posture_state' => $posture,
            'last_event' => $lastEvent,
            'region_id' => 5,
        ];
    }

    /**
     * Dois alarmes na mesma mensagem saem os dois: uma apneia e uma bradicardia no mesmo
     * minuto é precisamente quando alguém está pior, e com um campo só o segundo desaparecia
     * sem deixar rasto no log nem no Redis.
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
            'breathing_status_per_minute' => 'apnea',
            'heart_rate_status_per_minute' => 'low',
            'vital_signs_status' => 'weak',
            'sleep_state_status' => 'undefined',
        ], $topic, $this->device());

        self::assertSame(
            ['apnea', 'heart_rate_low', 'vitals_signal_lost'],
            array_column(array_column($result['events'], 'data'), 'detectionType'),
        );
    }

    /**
     * Um vocabulário só no payload: os quatro estados do `hbstatics` saem em enumeração,
     * como o `posture` e o `sleep_state` já saíam.
     */
    public function testTheMinuteStatsCarryEnumsAndNotVendorLabels(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'hbstatics',
            'device_code' => 'radar-topic-uid',
            'real_time_breathing' => 12,
            'real_time_heart_rate' => 66,
            'avg_breathing_per_minute' => 13,
            'avg_heart_rate_per_minute' => 70,
            'breathing_status_per_minute' => 'hypopnea',
            'heart_rate_status_per_minute' => 'normal',
            'vital_signs_status' => 'normal',
            'sleep_state_status' => 'light_sleep',
        ], $topic, $this->device());

        $data = $result['telemetry']['vitals_minute_stats']['data'];

        self::assertSame('hypopnea', $data['breathing_status_per_minute']);
        self::assertSame('normal', $data['heart_rate_status_per_minute']);
        self::assertSame('normal', $data['vital_signs_status']);
        self::assertSame('light_sleep', $data['sleep_state_status']);
    }

    /**
     * O grau de um alarme é uma enumeração inglesa, como todo o resto do envelope. Saía
     * `aviso` e `perigo`, que punha português no fio e deixava a tradução sem sítio.
     */
    public function testTheDetectionLevelIsAnEnglishEnum(): void
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
            'breathing_status_per_minute' => 'apnea',
            'heart_rate_status_per_minute' => 'high',
            'vital_signs_status' => 'normal',
            'sleep_state_status' => 'undefined',
        ], $topic, $this->device());

        $levels = array_column(array_column($result['events'], 'data'), 'detectionLevel');

        self::assertSame(['danger', 'warning'], $levels);
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
