<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer;
use Hub\Ingress\Mqtt\Qinglanst\Topic;
use PHPUnit\Framework\TestCase;

final class MessageNormalizerTest extends TestCase
{
    /**
     * Uma mensagem `heartbreath` dá três leituras, cada uma com a sua capacidade.
     *
     * A frequência cardíaca e a respiratória usam as formas do `Hub\FeatureNormalizer` --
     * `{bpm}` e `{breathsPerMinute}` --, as mesmas de um relógio, e é isso que lhes dá os
     * cartões que já existem em vez de um cartão feito à mão só para o radar.
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
            'sleep_state' => 'Light Sleep',
        ], $topic, $this->device());

        self::assertSame(
            ['heart_rate', 'breath_rate', 'sleep_state'],
            array_keys($result['telemetry']),
        );
        self::assertSame(['bpm' => 72], $result['telemetry']['heart_rate']['data']);
        self::assertSame(['breathsPerMinute' => 12], $result['telemetry']['breath_rate']['data']);
        self::assertSame(['state' => 'light_sleep'], $result['telemetry']['sleep_state']['data']);

        self::assertSame('radar-topic-uid', $result['telemetry']['heart_rate']['device']['id']);
        self::assertSame('Qinglanst RD-V1 Pro', $result['telemetry']['heart_rate']['device']['commercialName']);
        self::assertSame('heartbreath', $result['telemetry']['heart_rate']['source']['nativeType']);
    }

    /**
     * Um zero não é uma leitura: é o radar a dizer que não mediu ninguém.
     *
     * Publicá-lo punha o cartão a dizer "0 bpm", que se lê como um coração parado. O
     * `Undefined` do estado de sono é o mesmo caso -- é a ausência de medição, e o mapper
     * devolve-o também quando o código não é nenhum dos conhecidos.
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
            'sleep_state' => 'Undefined',
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
                'posture_state' => 'Fall Confirmation',
                'last_event' => 'No Event',
                'region_id' => 5,
            ]],
        ], $topic, $this->device());

        $event = $result['events'][0];
        self::assertSame('fall', $event['type']);
        self::assertSame('radar-topic-uid', $event['device']['id']);
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
            'breathing_status_per_minute' => 'Apnea',
            'heart_rate_status_per_minute' => 'Normal',
            'vital_signs_status' => 'Normal',
            'sleep_state_status' => 'Undefined',
        ], $topic, $this->device());

        self::assertSame('vitals_alarm', $vitals['events'][0]['type']);
        self::assertSame('apnea', $vitals['events'][0]['data']['detectionType']);

        $presence = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [$this->person(1, 'Walking', 'Leave Room')],
        ], $topic, $this->device());

        self::assertSame('presence_event', $presence['events'][0]['type']);
        self::assertSame('room_exit', $presence['events'][0]['data']['detectionType']);
    }

    /** A telemetria e as detecções vão na mesma versão: é o mesmo protocolo. */
    public function testDetectionsUseTheSameSchemaVersionAsTelemetry(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [$this->person(1, 'Fall Confirmation')],
        ], $topic, $this->device());

        self::assertSame(2, $result['events'][0]['schemaVersion']);
        self::assertSame(2, $result['telemetry']['presence']['schemaVersion']);
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
     * Cada pessoa mantém a sua postura.
     *
     * A postura é da pessoa, tal como a posição: numa divisão com três, uma pode estar de pé,
     * outra caída e outra a andar. Uma leitura do aparelho com uma postura só obrigava a
     * escolher entre elas e a deitar fora as outras.
     */
    public function testEveryPersonKeepsTheirOwnPosture(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [
                $this->person(1, 'Standing'),
                $this->person(2, 'Fall Confirmation'),
                $this->person(3, 'Walking'),
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
    private function person(int $index, string $posture, string $lastEvent = 'No Event'): array
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
