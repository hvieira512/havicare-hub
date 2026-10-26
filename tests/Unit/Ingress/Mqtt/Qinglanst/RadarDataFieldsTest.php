<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\Cloud\QinglanstCloudCommands;
use Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer;
use Hub\Ingress\Mqtt\Qinglanst\PayloadDecoder;
use Hub\Ingress\Mqtt\Qinglanst\QinglanstTopic;
use PHPUnit\Framework\TestCase;

/** Os nomes do `data` de cada tipo que o radar publica: são contrato, e mudar um é revisão. */
final class RadarDataFieldsTest extends TestCase
{
    public function testVitalsMinuteStatsCarryTheUnitInEveryRate(): void
    {
        $data = $this->telemetry('hbstatics', [0xFF, 12, 66, 0, 0, 17, 70, 0, 0, 0, 0, 0, 0, 0, 0, 0])['vitals_minute_stats']['data'];

        self::assertSame([
            'realTimeBreathsPerMinute' => 12,
            'realTimeHeartRateBpm' => 66,
            'avgBreathsPerMinute' => 17,
            'avgHeartRateBpm' => 70,
            'breathingStatus' => 'normal',
            'heartRateStatus' => 'normal',
            'vitalSignsStatus' => 'normal',
            'sleepState' => 'undefined',
        ], $data);
    }

    public function testPositionMinuteStatsCountPeopleAndWalkInMetres(): void
    {
        $data = $this->telemetry('posstatics', [0x01, 2, 3, 0x00, 0x2A, 5, 6, 7, 8, 9, 0x01, 0, 0, 0, 0, 0])['position_minute_stats']['data'];

        self::assertSame([
            'version' => 2,
            'peopleCount' => 3,
            'walkingDistanceMeters' => 42,
            'walkingTimeS' => 5,
            'meditationTimeS' => 6,
            'inBedTimeS' => 7,
            'standingTimeS' => 8,
            'multiplayerTimeS' => 9,
            'breathingActive' => true,
        ], $data);
    }

    public function testPresenceListsPeopleWithTheirArea(): void
    {
        $data = $this->telemetry('position', [0, 5, 0xFB, 120, 0, 0, 0, 0, 0, 0, 0, 0, 30, 6, 3, 2])['presence']['data'];

        self::assertSame([
            'peopleCount' => 1,
            'people' => [[
                'personIndex' => 0,
                'xPositionDm' => 5,
                'yPositionDm' => -5,
                'zPositionCm' => 120,
                'timeLeftS' => 30,
                'posture' => 'lying_down',
                'lastEvent' => 'enter_area',
                'areaId' => 2,
            ]],
        ], $data);
    }

    /** A queda usa as posturas da presença: quem lê uma não precisa de outro dicionário para a outra. */
    public function testFallAndPresenceShareOnePostureVocabulary(): void
    {
        $fallen = $this->events('position', [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 5, 0, 0]);
        $seated = $this->events('position', [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 8, 0, 0]);
        $presence = $this->telemetry('position', [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 8, 0, 0])['presence']['data'];

        self::assertSame(['confirmed' => true, 'posture' => 'lying_down', 'personIndex' => 0], $fallen[0]['data']);
        self::assertSame(['confirmed' => true, 'posture' => 'sitting_on_ground', 'personIndex' => 0], $seated[0]['data']);
        self::assertSame('sitting_on_ground', $presence['people'][0]['posture']);
    }

    public function testRadarConnectivityUsesTheSharedInterfaceEnum(): void
    {
        $commands = new QinglanstCloudCommands();

        self::assertSame(['interface' => 'cellular', 'signalQuality' => 20], $commands->telemetry(['signal_intensity' => 20])['connectivity']);
        self::assertSame(['interface' => 'wifi', 'signalStrengthDbm' => -60], $commands->telemetry(['signal_intensity' => -60])['connectivity']);
    }

    /**
     * @param list<int> $bytes
     * @return array<string, array<string, mixed>>
     */
    private function telemetry(string $type, array $bytes): array
    {
        return $this->normalize($type, $bytes)['telemetry'];
    }

    /**
     * @param list<int> $bytes
     * @return list<array<string, mixed>>
     */
    private function events(string $type, array $bytes): array
    {
        return $this->normalize($type, $bytes)['events'];
    }

    /**
     * @param list<int> $bytes
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function normalize(string $type, array $bytes): array
    {
        $decoded = (new PayloadDecoder())->decode($type, base64_encode(pack('C*', ...$bytes)), 'radar-uid');
        self::assertIsArray($decoded);

        return (new MessageNormalizer())->normalize($decoded, QinglanstTopic::parse('radar/1001/radar-uid'), [
            'imei' => 'radar-uid',
            'supplier' => 'Qinglanst',
            'model' => 'HC2',
            'deviceType' => 'radar',
            'licenseId' => 1001,
        ]);
    }
}
