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
     * cartões que já existem. Antes eram um envelope `vitals` com `heart_rate` e
     * `breathing` lá dentro, e o estado de sono ficava a viver como sub-campo de um cartão
     * feito à mão só para o radar.
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
     * Cada detecção sai na capacidade a que pertence, com o tipo específico dentro.
     *
     * As quinze saíam todas como `detection`, e o tipo real ficava escondido no payload.
     * Uma queda vista por um radar e um SOS de uma pulseira não se conseguiam listar nem
     * alertar pela mesma regra.
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

    /** A telemetria já ia em 2 e as detecções ficaram em 1: o mesmo protocolo, duas versões. */
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
        // Sem ninguém não há postura: o cartão fica sem leitura em vez de dizer "desconhecida".
        self::assertArrayNotHasKey('posture', $result['telemetry']);
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

        self::assertSame(
            ['state' => 'fall_confirmation', 'personIndex' => 2, 'lastEvent' => 'leave_room'],
            $result['telemetry']['posture']['data'],
        );

        self::assertSame('fall', $result['events'][0]['type']);
        self::assertSame('fall_confirmed', $result['events'][0]['data']['detectionType']);
        self::assertSame(2, $result['events'][0]['data']['details']['person_index']);
    }

    /**
     * Com duas pessoas na divisão, a postura que manda é a mais grave.
     *
     * A leitura é do aparelho e não da pessoa, por isso só cabe uma. Escolher a primeira
     * da lista deixava uma queda confirmada escondida atrás de alguém de pé ao lado.
     */
    public function testThePostureCardShowsTheMostSevereOfThePeoplePresent(): void
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

        self::assertSame(3, $result['telemetry']['presence']['data']['count']);
        self::assertSame('fall_confirmation', $result['telemetry']['posture']['data']['state']);
        self::assertSame(2, $result['telemetry']['posture']['data']['personIndex']);
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
