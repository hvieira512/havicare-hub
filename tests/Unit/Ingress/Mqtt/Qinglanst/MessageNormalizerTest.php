<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer;
use Hub\Ingress\Mqtt\Qinglanst\QinglanstTopic;
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
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'heartbreath',
            'device_code' => 'radar-topic-uid',
            'breathing' => 0,
            'heart_rate' => 0,
            'sleep_state' => 'undefined',
        ], $topic, $this->device());

        self::assertSame([], $result['telemetry']);
        // Um quarto vazio também não é alarme: quem diz que o sinal está fraco é o `hbstatics`.
        self::assertSame([], $result['events']);
    }

    public function testNormalizesPosStaticsTelemetryUsingRawNativeType(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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
        self::assertSame('position_minute_stats', $telemetry['type']);
        self::assertSame('posstatics', $telemetry['source']['nativeType']);
        self::assertSame(42, $telemetry['data']['walkingDistance']);
    }

    public function testPositionDetectionIncludesDeviceAndSourceMetadata(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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
        self::assertSame(['confirmed' => true, 'posture' => 'lying', 'personIndex' => 1], $event['data']);
    }

    /** O `type` diz o que aconteceu, com o mesmo nome que um relógio usa para a mesma coisa. */
    public function testEachDetectionIsTypedByWhatHappened(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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

        self::assertSame('apnea', $vitals['events'][0]['type']);
        self::assertSame([], $vitals['events'][0]['data']);
        self::assertSame('hbstatics', $vitals['events'][0]['source']['nativeType']);

        $presence = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [$this->person(1, 'walking', 'leave_room')],
        ], $topic, $this->device());

        self::assertSame('zone_exit', $presence['events'][0]['type']);
        self::assertSame(['zone' => 'room', 'personIndex' => 1], $presence['events'][0]['data']);
    }

    /** A telemetria e as detecções vão na mesma versão: é o mesmo protocolo. */
    public function testTheEnvelopeCarriesNoSchemaVersion(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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
        self::assertSame(2, $result['events'][0]['data']['personIndex']);
    }

    /**
     * A postura é da pessoa, como a posição: numa divisão com três, uma leitura do aparelho
     * com uma postura só obrigava a escolher entre elas.
     */
    public function testEveryPersonKeepsTheirOwnPosture(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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

    /** O valor leva o nome e a unidade da telemetria `heart_rate`. */
    public function testAHighHeartRateCarriesTheBpmThatRaisedIt(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'heartbreath',
            'device_code' => 'radar-topic-uid',
            'breathing' => 14,
            'heart_rate' => 180,
        ], $topic, $this->device());

        self::assertSame('heart_rate_high', $result['events'][0]['type']);
        self::assertSame(['bpm' => 180], $result['events'][0]['data']);
    }

    /**
     * O estado que o radar reporta levanta o alarme que lhe pertence.
     *
     * @dataProvider breathingAlarms
     */
    public function testTheReportedBreathingStatusRaisesItsAlarm(int $statusByte, string $expected): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'hbstatics',
            'device_code' => 'radar-topic-uid',
            'real_time_breathing' => 16,
            'real_time_heart_rate' => 70,
            'avg_breathing_per_minute' => 16,
            'avg_heart_rate_per_minute' => 70,
            'breathing_status_per_minute' => $statusByte === 1 ? 'hypopnea' : 'hyperpnea',
            'heart_rate_status_per_minute' => 'normal',
            'vital_signs_status' => 'normal',
            'sleep_state_status' => 'awake',
        ], $topic, $this->device());

        self::assertContains($expected, array_column($result['events'], 'type'));
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function breathingAlarms(): array
    {
        return [
            'respiração fraca' => [1, 'breath_rate_low'],
            'respiração acelerada' => [2, 'breath_rate_high'],
        ];
    }

    /** Sentado no chão, confirmado, é uma queda: a postura diz como ficou. */
    public function testSittingOnTheGroundIsAFall(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [$this->person(1, 'confirmed_sitting_on_ground')],
        ], $topic, $this->device());

        self::assertSame('fall', $result['events'][0]['type']);
        self::assertSame(
            ['confirmed' => true, 'posture' => 'sitting_on_ground', 'personIndex' => 1],
            $result['events'][0]['data'],
        );
    }

    /**
     * Os sete ramos da detecção de posição, com o tipo e o `data` de cada um.
     *
     * @dataProvider positionDetections
     * @param array<string, mixed> $expectedData
     */
    public function testEachPostureAndMovementRaisesItsOwnDetection(
        string $posture,
        string $lastEvent,
        string $expectedType,
        array $expectedData,
    ): void {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [$this->person(1, $posture, $lastEvent)],
        ], $topic, $this->device());

        self::assertCount(1, $result['events']);
        self::assertSame($expectedType, $result['events'][0]['type']);
        self::assertSame($expectedData, $result['events'][0]['data']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> */
    public static function positionDetections(): array
    {
        $fall = static fn (bool $confirmed, string $posture): array =>
            ['confirmed' => $confirmed, 'posture' => $posture, 'personIndex' => 1];
        $room = ['zone' => 'room', 'personIndex' => 1];
        $area = ['zone' => 'area', 'personIndex' => 1, 'areaId' => 5];

        return [
            'queda confirmada'  => ['fall_confirmation', 'no_event', 'fall', $fall(true, 'lying')],
            'queda suspeita'    => ['suspected_fall', 'no_event', 'fall', $fall(false, 'lying')],
            'sentado no chão'   => ['confirmed_sitting_on_ground', 'no_event', 'fall', $fall(true, 'sitting_on_ground')],
            'entrou na sala'    => ['walking', 'enter_room', 'zone_entry', $room],
            'saiu da sala'      => ['walking', 'leave_room', 'zone_exit', $room],
            'entrou na região'  => ['walking', 'enter_area', 'zone_entry', $area],
            'saiu da região'    => ['walking', 'leave_area', 'zone_exit', $area],
        ];
    }

    /** A postura ganha ao movimento: quem cai à entrada da sala dá a queda, não a entrada. */
    public function testThePostureWinsOverTheMovement(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [$this->person(1, 'fall_confirmation', 'enter_room')],
        ], $topic, $this->device());

        self::assertSame(['fall'], array_column($result['events'], 'type'));
    }

    /** Cada pessoa dá a sua detecção: a entrada de uma não esconde a queda da outra. */
    public function testEveryPersonRaisesTheirOwnDetection(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'device_code' => 'radar-topic-uid',
            'people' => [
                $this->person(1, 'walking', 'enter_room'),
                $this->person(2, 'fall_confirmation'),
            ],
        ], $topic, $this->device());

        self::assertSame(['zone_entry', 'fall'], array_column($result['events'], 'type'));
        self::assertSame([1, 2], array_column(array_column($result['events'], 'data'), 'personIndex'));
    }

    /** @return array<string, mixed> */
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
     * minuto é precisamente quando alguém está pior.
     */
    public function testEveryAlarmInOneMessageSurvives(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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
            ['apnea', 'heart_rate_low', 'weak_vital_signs'],
            array_column($result['events'], 'type'),
        );
    }

    /**
     * Um vocabulário só no payload: os quatro estados do `hbstatics` saem em enumeração, como
     * o `posture` e o `sleep_state`.
     */
    public function testTheMinuteStatsCarryEnumsAndNotVendorLabels(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

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

        self::assertSame('hypopnea', $data['breathingStatus']);
        self::assertSame('normal', $data['heartRateStatus']);
        self::assertSame('normal', $data['vitalSignsStatus']);
        self::assertSame('light_sleep', $data['sleepState']);
    }

    /** O estado do minuto leva a média como valor: é o que justifica o evento. */
    public function testTheMinuteStatusCarriesTheAverageThatJustifiesIt(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'hbstatics',
            'device_code' => 'radar-topic-uid',
            'real_time_breathing' => 30,
            'real_time_heart_rate' => 130,
            'avg_breathing_per_minute' => 28,
            'avg_heart_rate_per_minute' => 128,
            'breathing_status_per_minute' => 'hyperpnea',
            'heart_rate_status_per_minute' => 'high',
            'vital_signs_status' => 'normal',
            'sleep_state_status' => 'undefined',
        ], $topic, $this->device());

        self::assertSame(['breath_rate_high', 'heart_rate_high'], array_column($result['events'], 'type'));
        self::assertSame([['breathsPerMinute' => 28], ['bpm' => 128]], array_column($result['events'], 'data'));
    }

    /**
     * A chave do mapa de telemetria é a capacidade e tem de ser o `type` do envelope: senão o
     * catálogo declara uma capacidade que ninguém recebe, e a falha é calada.
     *
     * @dataProvider everyMessageType
     * @param array<string, mixed> $decoded
     */
    public function testTheEnvelopeTypeIsAlwaysTheCapabilityItIsFiledUnder(array $decoded): void
    {
        $result = (new MessageNormalizer())->normalize(
            $decoded,
            QinglanstTopic::parse('radar/1001/radar-topic-uid'),
            $this->device(),
        );

        foreach ($result['telemetry'] as $capability => $envelope) {
            self::assertSame($capability, $envelope['type']);
        }
    }

    /**
     * O `type` de uma capacidade é snake_case; os campos dentro do `data` são camelCase. São
     * dois vocabulários, e no radar é fácil o segundo escorregar para o primeiro.
     *
     * @dataProvider everyMessageType
     * @param array<string, mixed> $decoded
     */
    public function testNoPublishedFieldIsSnakeCase(array $decoded): void
    {
        $result = (new MessageNormalizer())->normalize(
            $decoded,
            QinglanstTopic::parse('radar/1001/radar-topic-uid'),
            $this->device(),
        );

        $offenders = [];
        $walk = static function (mixed $node) use (&$walk, &$offenders): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (is_string($key) && str_contains($key, '_')) {
                    $offenders[] = $key;
                }
                $walk($value);
            }
        };

        foreach ($result['telemetry'] as $envelope) {
            $walk($envelope['data']);
        }
        foreach ($result['events'] as $event) {
            $walk($event['data']);
        }

        self::assertSame([], array_values(array_unique($offenders)));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function everyMessageType(): array
    {
        return [
            'position' => [[
                'type' => 'position',
                'device_code' => 'radar-topic-uid',
                'people' => [[
                    'person_index' => 1,
                    'x_position_dm' => 1,
                    'y_position_dm' => 2,
                    'z_position_cm' => 3,
                    'time_left_s' => 4,
                    'posture_state' => 'standing',
                    'last_event' => 'no_event',
                    'region_id' => 5,
                ]],
            ]],
            'heartbreath' => [[
                'type' => 'heartbreath',
                'device_code' => 'radar-topic-uid',
                'breathing' => 12,
                'heart_rate' => 72,
                'sleep_state' => 'light_sleep',
            ]],
            'posstatics' => [[
                'type' => 'posstatics',
                'device_code' => 'radar-topic-uid',
                'version' => 2,
                'people' => 1,
                'walking_distance' => 10,
                'walking_time' => 5,
                'meditation_time' => 1,
                'in_bed_time' => 2,
                'standing_time' => 3,
                'multiplayer_time' => 0,
                'breathing_active' => true,
            ]],
            'hbstatics' => [[
                'type' => 'hbstatics',
                'device_code' => 'radar-topic-uid',
                'real_time_breathing' => 12,
                'real_time_heart_rate' => 66,
                'avg_breathing_per_minute' => 13,
                'avg_heart_rate_per_minute' => 70,
                'breathing_status_per_minute' => 'normal',
                'heart_rate_status_per_minute' => 'normal',
                'vital_signs_status' => 'normal',
                'sleep_state_status' => 'light_sleep',
            ]],
        ];
    }

    /**
     * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: int, company: string}
     */
    private function device(): array
    {
        return [
            'imei' => 'canonical-radar-id',
            'supplier' => 'Qinglanst',
            'model' => 'RD-V1',
            'commercialName' => 'Qinglanst RD-V1 Pro',
            'deviceType' => 'radar',
            'licenseId' => 1001,
            'company' => 'hitcare',
        ];
    }
}
