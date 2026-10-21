<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer;
use Hub\Ingress\Mqtt\Qinglanst\QinglanstTopic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageNormalizerTest extends TestCase
{
    /** Usa as formas do `FeatureNormalizer`, as mesmas dos relógios, para o radar herdar os cartões que já existem. */
    public function testHeartBreathBecomesThreeCanonicalReadings(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'heartbreath',
            'deviceCode' => 'radar-topic-uid',
            'breathsPerMinute' => 12,
            'heartRateBpm' => 72,
            'sleepState' => 'light_sleep',
        ], $topic, $this->device());

        self::assertSame(
            ['heart_rate', 'breath_rate', 'sleep_state'],
            array_keys($result['telemetry']),
        );
        self::assertSame(['bpm' => 72], $result['telemetry']['heart_rate']['data']);
        self::assertSame(['breathsPerMinute' => 12], $result['telemetry']['breath_rate']['data']);
        self::assertSame(['state' => 'light_sleep'], $result['telemetry']['sleep_state']['data']);

        self::assertSame('canonical-radar-id', $result['telemetry']['heart_rate']['device']['id']);
        self::assertSame('Qinglanst HC2 Pro', $result['telemetry']['heart_rate']['device']['commercialName']);
        self::assertSame('heartbreath', $result['telemetry']['heart_rate']['source']['nativeType']);
    }

    /** Um zero é o radar a dizer que não mediu ninguém, e «0 bpm» lê-se como coração parado; o `Undefined` do sono é igual. */
    public function testAbsentMeasurementsDoNotBecomeReadings(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'heartbreath',
            'deviceCode' => 'radar-topic-uid',
            'breathsPerMinute' => 0,
            'heartRateBpm' => 0,
            'sleepState' => 'undefined',
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
            'deviceCode' => 'radar-topic-uid',
            'version' => 2,
            'people' => 1,
            'walkingDistanceMeters' => 42,
            'walkingTimeS' => 4,
            'meditationTimeS' => 5,
            'inBedTimeS' => 6,
            'standingTimeS' => 7,
            'multiplayerTimeS' => 8,
            'breathingActive' => true,
        ], $topic, $this->device());

        $telemetry = $result['telemetry']['position_minute_stats'];
        self::assertSame('position_minute_stats', $telemetry['type']);
        self::assertSame('posstatics', $telemetry['source']['nativeType']);
        self::assertSame(42, $telemetry['data']['walkingDistanceMeters']);
    }

    public function testPositionNormalizationDropsSentinelPersonIndex88(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'deviceCode' => 'radar-topic-uid',
            'people' => [[
                'personIndex' => 88,
                'xPositionDm' => 0,
                'yPositionDm' => 0,
                'zPositionCm' => 0,
                'timeLeftS' => 0,
                'posture' => 'Unknown',
                'lastEvent' => 'no_event',
                'areaId' => 0,
            ]],
        ], $topic, $this->device());

        self::assertSame(0, $result['telemetry']['presence']['data']['peopleCount']);
        self::assertSame([], $result['telemetry']['presence']['data']['people']);
        self::assertSame([], $result['events']);
    }

    public function testPositionNormalizationKeepsRealPeopleWhenSentinelIsPresent(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'deviceCode' => 'radar-topic-uid',
            'people' => [
                [
                    'personIndex' => 88,
                    'xPositionDm' => 0,
                    'yPositionDm' => 0,
                    'zPositionCm' => 0,
                    'timeLeftS' => 0,
                    'posture' => 'Unknown',
                    'lastEvent' => 'no_event',
                    'areaId' => 0,
                ],
                [
                    'personIndex' => 2,
                    'xPositionDm' => 4,
                    'yPositionDm' => 5,
                    'zPositionCm' => 6,
                    'timeLeftS' => 7,
                    'posture' => 'fallen',
                    'lastEvent' => 'leave_room',
                    'areaId' => 8,
                ],
            ],
        ], $topic, $this->device());

        $presence = $result['telemetry']['presence']['data'];
        self::assertSame(1, $presence['peopleCount']);
        self::assertCount(1, $presence['people']);
        self::assertSame(2, $presence['people'][0]['personIndex']);
        self::assertSame('fallen', $presence['people'][0]['posture']);
        self::assertSame('leave_room', $presence['people'][0]['lastEvent']);

        self::assertSame('fall', $result['events'][0]['type']);
        self::assertSame(2, $result['events'][0]['data']['personIndex']);
    }

    /** A postura é da pessoa, como a posição: numa divisão com três, uma postura só do aparelho não diz de quem é. */
    public function testEveryPersonKeepsTheirOwnPosture(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'deviceCode' => 'radar-topic-uid',
            'people' => [
                $this->person(1, 'standing'),
                $this->person(2, 'fallen'),
                $this->person(3, 'walking'),
            ],
        ], $topic, $this->device());

        $presence = $result['telemetry']['presence']['data'];
        self::assertSame(3, $presence['peopleCount']);
        self::assertSame(
            ['standing', 'fallen', 'walking'],
            array_column($presence['people'], 'posture'),
        );
        self::assertSame([1, 2, 3], array_column($presence['people'], 'personIndex'));

        // A queda continua a ser alarme: é isso que a põe à frente de quem olha, sem a
        // telemetria ter de escolher uma pessoa entre as presentes.
        self::assertSame('fall', $result['events'][0]['type']);
    }

    /** O alarme é o estado de cada minuto, calculado pelo radar com os limites dele; uma amostra fora do normal não o é. */
    public function testASampleOutsideTheUsualRangeRaisesNoAlarm(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        foreach ([180, 30] as $bpm) {
            $result = $normalizer->normalize([
                'type' => 'heartbreath',
                'deviceCode' => 'radar-topic-uid',
                'breathsPerMinute' => 14,
                'heartRateBpm' => $bpm,
            ], $topic, $this->device());

            self::assertSame([], $result['events']);
            self::assertSame(['bpm' => $bpm], $result['telemetry']['heart_rate']['data']);
        }
    }

    /**
     * Os sete ramos da detecção de posição, com o tipo e o `data` de cada um.
     *
     * @param array<string, mixed> $expectedData
     */
    #[DataProvider('positionDetections')]
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
            'deviceCode' => 'radar-topic-uid',
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
            'confirmed fall'  => ['fallen', 'no_event', 'fall', $fall(true, 'lying_down')],
            'suspected fall'    => ['suspected_fall', 'no_event', 'fall', $fall(false, 'lying_down')],
            'sitting on the floor'   => ['sitting_on_ground', 'no_event', 'fall', $fall(true, 'sitting_on_ground')],
            'entered the room'    => ['walking', 'enter_room', 'zone_entry', $room],
            'left the room'      => ['walking', 'leave_room', 'zone_exit', $room],
            'entered the area'  => ['walking', 'enter_area', 'zone_entry', $area],
            'left the area'    => ['walking', 'leave_area', 'zone_exit', $area],
        ];
    }

    /** A postura ganha ao movimento: quem cai à entrada da sala dá a queda, não a entrada. */
    public function testThePostureWinsOverTheMovement(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'position',
            'deviceCode' => 'radar-topic-uid',
            'people' => [$this->person(1, 'fallen', 'enter_room')],
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
            'deviceCode' => 'radar-topic-uid',
            'people' => [
                $this->person(1, 'walking', 'enter_room'),
                $this->person(2, 'fallen'),
            ],
        ], $topic, $this->device());

        self::assertSame(['zone_entry', 'fall'], array_column($result['events'], 'type'));
        self::assertSame([1, 2], array_column(array_column($result['events'], 'data'), 'personIndex'));
    }

    /** @return array<string, mixed> */
    private function person(int $index, string $posture, string $lastEvent = 'no_event'): array
    {
        return [
            'personIndex' => $index,
            'xPositionDm' => 1,
            'yPositionDm' => 2,
            'zPositionCm' => 3,
            'timeLeftS' => 4,
            'posture' => $posture,
            'lastEvent' => $lastEvent,
            'areaId' => 5,
        ];
    }

    /** Uma apneia e uma bradicardia no mesmo minuto é quando alguém está pior, e saem as duas. */
    public function testEveryAlarmInOneMessageSurvives(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'hbstatics',
            'deviceCode' => 'radar-topic-uid',
            'realTimeBreathsPerMinute' => 0,
            'realTimeHeartRateBpm' => 0,
            'avgBreathsPerMinute' => 0,
            'avgHeartRateBpm' => 30,
            'breathingStatus' => 'apnea',
            'heartRateStatus' => 'low',
            'vitalSignsStatus' => 'weak',
            'sleepState' => 'undefined',
        ], $topic, $this->device());

        self::assertSame(
            ['apnea', 'heart_rate_low', 'weak_vital_signs'],
            array_column($result['events'], 'type'),
        );
    }

    /** Os quatro estados do `hbstatics` saem em enumeração, como o `posture` e o `sleep_state`. */
    public function testTheMinuteStatsCarryEnumsAndNotVendorLabels(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'hbstatics',
            'deviceCode' => 'radar-topic-uid',
            'realTimeBreathsPerMinute' => 12,
            'realTimeHeartRateBpm' => 66,
            'avgBreathsPerMinute' => 13,
            'avgHeartRateBpm' => 70,
            'breathingStatus' => 'hypopnea',
            'heartRateStatus' => 'normal',
            'vitalSignsStatus' => 'normal',
            'sleepState' => 'light_sleep',
        ], $topic, $this->device());

        $data = $result['telemetry']['vitals_minute_stats']['data'];

        self::assertSame('hypopnea', $data['breathingStatus']);
        self::assertSame('normal', $data['heartRateStatus']);
        self::assertSame('normal', $data['vitalSignsStatus']);
        self::assertSame('light_sleep', $data['sleepState']);
        self::assertSame(['breath_rate_low'], array_column($result['events'], 'type'));
    }

    /** O estado do minuto leva a média como valor: é o que justifica o evento. */
    public function testTheMinuteStatusCarriesTheAverageThatJustifiesIt(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = QinglanstTopic::parse('radar/1001/radar-topic-uid');

        $result = $normalizer->normalize([
            'type' => 'hbstatics',
            'deviceCode' => 'radar-topic-uid',
            'realTimeBreathsPerMinute' => 30,
            'realTimeHeartRateBpm' => 130,
            'avgBreathsPerMinute' => 28,
            'avgHeartRateBpm' => 128,
            'breathingStatus' => 'hyperpnea',
            'heartRateStatus' => 'high',
            'vitalSignsStatus' => 'normal',
            'sleepState' => 'undefined',
        ], $topic, $this->device());

        self::assertSame(['breath_rate_high', 'heart_rate_high'], array_column($result['events'], 'type'));
        self::assertSame([['breathsPerMinute' => 28], ['bpm' => 128]], array_column($result['events'], 'data'));
    }

    /**
     * A chave do mapa de telemetria tem de ser o `type` do envelope, senão o catálogo declara uma capacidade que ninguém recebe.
     *
     * @param array<string, mixed> $decoded
     */
    #[DataProvider('everyMessageType')]
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
     * O `type` é snake_case e os campos do `data` são camelCase, e no radar o segundo escorrega facilmente para o primeiro.
     *
     * @param array<string, mixed> $decoded
     */
    #[DataProvider('everyMessageType')]
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
                'deviceCode' => 'radar-topic-uid',
                'people' => [[
                    'personIndex' => 1,
                    'xPositionDm' => 1,
                    'yPositionDm' => 2,
                    'zPositionCm' => 3,
                    'timeLeftS' => 4,
                    'posture' => 'standing',
                    'lastEvent' => 'no_event',
                    'areaId' => 5,
                ]],
            ]],
            'heartbreath' => [[
                'type' => 'heartbreath',
                'deviceCode' => 'radar-topic-uid',
                'breathsPerMinute' => 12,
                'heartRateBpm' => 72,
                'sleepState' => 'light_sleep',
            ]],
            'posstatics' => [[
                'type' => 'posstatics',
                'deviceCode' => 'radar-topic-uid',
                'version' => 2,
                'people' => 1,
                'walkingDistanceMeters' => 10,
                'walkingTimeS' => 5,
                'meditationTimeS' => 1,
                'inBedTimeS' => 2,
                'standingTimeS' => 3,
                'multiplayerTimeS' => 0,
                'breathingActive' => true,
            ]],
            'hbstatics' => [[
                'type' => 'hbstatics',
                'deviceCode' => 'radar-topic-uid',
                'realTimeBreathsPerMinute' => 12,
                'realTimeHeartRateBpm' => 66,
                'avgBreathsPerMinute' => 13,
                'avgHeartRateBpm' => 70,
                'breathingStatus' => 'normal',
                'heartRateStatus' => 'normal',
                'vitalSignsStatus' => 'normal',
                'sleepState' => 'light_sleep',
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
            'model' => 'HC2',
            'commercialName' => 'Qinglanst HC2 Pro',
            'deviceType' => 'radar',
            'licenseId' => 1001,
            'company' => 'hitcare',
        ];
    }
}
