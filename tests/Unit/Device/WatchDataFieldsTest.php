<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\FeatureNormalizer;
use Hub\Ingress\Tcp\FourPTouch\FourPTouchEventDecoder;
use Hub\Ingress\Tcp\Vivistar\VivistarEventDecoder;
use Hub\Ingress\Tcp\Wonlex\WonlexEventDecoder;
use PHPUnit\Framework\TestCase;

/** Os nomes do `data` de cada tipo que os relógios publicam: são contrato, e mudar um é revisão. */
final class WatchDataFieldsTest extends TestCase
{
    /** O sinal de vida só repetia a bateria e os passos, que saem nos seus tipos, e inteiros crus do fabricante. */
    public function testNoWatchPublishesAHeartbeat(): void
    {
        $features = array_column([
            ...VivistarEventDecoder::decode('AP03', ['gsmSignal' => 60, 'satelliteCount' => 9, 'battery' => 80, 'workMode' => 2, 'steps' => 5555]),
            ...WonlexEventDecoder::decode('heartbeat', ['batteryLevel' => 100, 'batteryState' => 1]),
            ...FourPTouchEventDecoder::decode('LK', ['steps' => 10, 'batteryPercent' => 90]),
        ], 'feature');

        self::assertSame(['battery', 'activity', 'battery', 'activity', 'battery'], $features);
    }

    public function testBatterySaysWhetherItChargesAndWhyItWasSent(): void
    {
        self::assertSame(
            ['percent' => 80, 'charging' => true, 'reportReason' => 'periodic'],
            FeatureNormalizer::normalize('battery', ['batteryLevel' => 80, 'batteryState' => 1, 'batteryType' => 2]),
        );
        self::assertSame(
            ['percent' => 15, 'charging' => false, 'reportReason' => 'low_battery', 'lowBattery' => true],
            FeatureNormalizer::normalize('battery', ['batteryLevel' => 15, 'batteryState' => 0, 'batteryType' => 3]),
        );
    }

    public function testWaveformsStartAtAnInstantAndDropThePacketStatus(): void
    {
        self::assertSame([
            'samples' => [110.2, 120.6],
            'frequencyHz' => 200,
            'collectionId' => '87654321',
            'startedAt' => '2022-05-22T14:00:00Z',
            'block' => 0,
        ], FeatureNormalizer::normalize('ecg', [
            'data' => '110.2, 120.6',
            'Frequency' => '200',
            'collectionLogo' => '87654321',
            'Data start time' => 1653228000000,
            'Data Status' => 0,
            'block' => 0,
        ]));
    }

    /** O mesmo nome em todo o lado: o ECG da pulseira já diz `hrvMilliseconds` e `rrIntervalMilliseconds`. */
    public function testHrvAndRrIntervalsUseTheNamesOfTheEcgSummary(): void
    {
        self::assertSame(['hrvMilliseconds' => 141], FeatureNormalizer::normalize('hrv', ['date' => '141']));
        self::assertSame([
            'intervals' => [
                ['measuredAt' => '2022-03-24T08:43:10Z', 'rrIntervalMilliseconds' => 1080],
                ['measuredAt' => '2022-03-24T08:43:11Z', 'rrIntervalMilliseconds' => 1570],
            ],
            'frequencyHz' => 200,
        ], FeatureNormalizer::normalize('rr_interval', ['data' => '1648111390074,1080;1648111391154,1570', 'Frequency' => '200']));
    }

    public function testDeviceStateCarriesNoRawStatus(): void
    {
        self::assertSame(['state' => 'factory_reset'], FeatureNormalizer::normalize('device_state', ['state' => 'factory_reset', 'status' => 1]));
    }

    /** As chaves e os valores que o fabricante usa na configuração não saem: o hub não as entende. */
    public function testDeviceConfigCarriesOnlyTheOutcome(): void
    {
        self::assertSame(['status' => 'ok'], FeatureNormalizer::normalize('device_config', [
            'configs' => ['upPPG' => ['interval' => '60']],
            'configAck' => '1',
        ]));
        self::assertSame(['status' => 'failed'], FeatureNormalizer::normalize('device_config', ['configAck' => '0']));
    }

    public function testLocationNamesTheUnitOfEveryNumber(): void
    {
        $location = FeatureNormalizer::normalize('location', [
            'gpsValid' => true,
            'lat' => 41.7,
            'lon' => -8.79,
            'speed' => 5.2,
            'direction' => 152,
            'gsmSignal' => 100,
            'baseStations' => [['lac' => '10133', 'cellId' => '5173', 'gsmSignal' => 100]],
            'wifi' => [['mac' => '1C:FA:68:13:A5:B4', 'signal' => -61]],
        ]);

        self::assertSame(152.0, $location['headingDegrees']);
        self::assertArrayNotHasKey('heading', $location);
        self::assertArrayNotHasKey('gsmSignal', $location);
        self::assertSame(['lac' => '10133', 'cellId' => '5173'], $location['baseStations'][0]);
        self::assertSame(['mac' => '1c:fa:68:13:a5:b4', 'signalStrengthDbm' => -61], $location['wifiAccessPoints'][0]);
    }

    /** Um troço de nome desconhecido fica sem tipo, e não com a etiqueta do fabricante. */
    public function testASleepSegmentOfUnknownKindHasNoType(): void
    {
        $sleep = FeatureNormalizer::normalize('sleep', ['segments' => [
            ['durationMinutes' => 30, 'sleepType' => 'Deep'],
            ['durationMinutes' => 10, 'sleepType' => 'Dozing'],
        ]]);

        self::assertSame([['durationMinutes' => 30, 'type' => 'deep_sleep'], ['durationMinutes' => 10]], $sleep['segments']);
    }
}
