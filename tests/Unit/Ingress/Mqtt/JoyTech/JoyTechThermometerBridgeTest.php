<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\JoyTech;

use Hub\Ingress\Mqtt\JoyTech\JoyTechFrame;
use Hub\Ingress\Mqtt\JoyTech\JoyTechThermometerBridge;
use Hub\Ingress\Mqtt\JoyTech\RecentMeasurementKeys;
use Hub\State\DeviceStore;
use Hub\State\RedisKeys;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Do gateway ao MQTT, com as tramas que o DET-3018B mandou à sonda a 2026-10-16. */
final class JoyTechThermometerBridgeTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const THERMOMETER = '748b34830e5b';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    private const CLOCK_REQUEST = 'faaaaaaf000a0610f55f';
    /** As 30 da memória, a mais recente primeiro: testa, 36,57 °C, 2026-10-16 16:42. Duas são de 2024, do reset das pilhas. */
    private const MEMORY = 'faaaaaaf00fa03020e491a0a10102a020e431a0a10102a020e361a0a0f0f12020e451a0a090c1e020e371a0a090c1d020e291a0a08091f020e431a0a010d29020e411a09041102020e481a08120a3a020e521a08120a39020dfd1a0811091c020e101a0811091c020dbd1a07180c16020dfd1a07180c16020e061a07180c16020e441a070b0b06020e1d1a07060c38020e201a07060c38020e2b1a07060c38020e381a07060c38020e261a07060c29020e311a07060c29020e3d1a061a0d08020e3e1a061a0d07020e2f1a061a0d07020e1b1a061a0c05020e1a180101153b020e1d1801011537020da81a06180c29020e141a06180c2915f55f';
    /** A ligação seguinte: uma medição nova, 36,39 °C às 16:45, à frente das mesmas 30. */
    private const MEMORY_AFTER_ONE_MORE = 'faaaaaaf00fa03020e371a0a10102d020e491a0a10102a020e431a0a10102a020e361a0a0f0f12020e451a0a090c1e020e371a0a090c1d020e291a0a08091f020e431a0a010d29020e411a09041102020e481a08120a3a020e521a08120a39020dfd1a0811091c020e101a0811091c020dbd1a07180c16020dfd1a07180c16020e061a07180c16020e441a070b0b06020e1d1a07060c38020e201a07060c38020e2b1a07060c38020e381a07060c38020e261a07060c29020e311a07060c29020e3d1a061a0d08020e3e1a061a0d07020e2f1a061a0d07020e1b1a061a0c05020e1a180101153b020e1d1801011537020da81a06180c293cf55f';

    private float $now;

    private RecentMeasurementKeys $seen;

    protected function setUp(): void
    {
        $this->now = (float)strtotime('2026-10-16T15:43:00Z');
        $this->seen = new RecentMeasurementKeys(new InMemoryRedisClient());
    }

    /** A sessão só repete o `session` ao abrir: são as tramas que a provam viva até ao fim. */
    public function testFramesKeepTheMeasuringSessionAlive(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DeviceStore($redis);
        $bridge = $this->bridge(new RecordingHubMqttBridge(), $store);
        IngressFixtures::relay($bridge, self::TOPIC, self::message('session', ['connected' => true]));
        $redis->zadd(RedisKeys::onlineDevices(), [self::THERMOMETER => time() - 200]);

        $this->now += 200;
        IngressFixtures::relay($bridge, self::TOPIC, self::frame(self::CLOCK_REQUEST));

        self::assertSame([], $store->expireStaleDevices());
    }

    /** O aparelho pede a hora 4 vezes por segundo e só mede depois de a ter: responde-se ao primeiro. */
    public function testTheClockRequestIsAnsweredOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        IngressFixtures::relay($bridge, self::TOPIC, self::message('session', ['connected' => true]));
        foreach ([0, 0.25, 0.5, 0.75] as $step) {
            $this->now += 0.25;
            IngressFixtures::relay($bridge, self::TOPIC, self::frame(self::CLOCK_REQUEST));
        }

        self::assertSame('measuring', $mqtt->statuses[0]['payload']['state']);
        self::assertCount(1, $mqtt->gatewayCommands);
        self::assertSame('answerClock', $mqtt->gatewayCommands[0]['payload']['operation']);
        self::assertStringStartsWith('faaaaaaf000f86', $mqtt->gatewayCommands[0]['payload']['payload']['writes'][0]['hex']);
    }

    /** A memória vem numa trama e responde-se-lhe: a mais recente com menos de 5 min é ao vivo, as outras são histórico, mesmo as de 2024. */
    public function testTheMemoryLeavesWithTheFirstOneLive(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($mqtt), self::TOPIC, self::frame(self::MEMORY));

        self::assertSame(['acknowledgeMeasurements'], array_column(array_column($mqtt->gatewayCommands, 'payload'), 'operation'));
        $temperatures = self::temperatures($mqtt);
        self::assertCount(28, $temperatures);
        $latest = $temperatures[27];
        self::assertSame(['bodyCelsius' => 36.6, 'site' => 'forehead'], $latest['data']);
        self::assertSame('2026-10-16T15:42:00Z', $latest['occurredAt']);
        // Duas leituras no mesmo minuto são duas medições.
        self::assertSame(['bodyCelsius' => 36.5, 'site' => 'forehead', 'fromHistory' => true], $temperatures[26]['data']);
        self::assertSame('2026-06-24T11:41:00Z', $temperatures[0]['occurredAt']);
    }

    /** O aparelho manda as mesmas 30 a cada ligação: uma medição nova acrescenta só essa. */
    public function testTheNextConnectionAddsOnlyTheNewMeasurement(): void
    {
        IngressFixtures::relay($this->bridge(new RecordingHubMqttBridge()), self::TOPIC, self::frame(self::MEMORY));
        $this->now += 180;

        $mqtt = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($mqtt), self::TOPIC, self::frame(self::MEMORY_AFTER_ONE_MORE));

        self::assertSame([['bodyCelsius' => 36.4, 'site' => 'forehead']], array_column(self::temperatures($mqtt), 'data'));
    }

    /** Em °F vem convertida; a medição de um objeto não é de ninguém. */
    public function testFahrenheitIsConvertedAndObjectsAreIgnored(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $records = pack('Cn', 1, 0x8000 | 9770) . pack('C5', 26, 10, 16, 16, 42)
            . pack('Cn', 3, 2150) . pack('C5', 26, 10, 16, 16, 41);
        IngressFixtures::relay($this->bridge($mqtt), self::TOPIC, self::frame(JoyTechFrame::encode(0x03, $records)));

        self::assertSame([['bodyCelsius' => 36.5, 'site' => 'ear']], array_column(self::temperatures($mqtt), 'data'));
    }

    /** @return list<array<string, mixed>> */
    private static function temperatures(RecordingHubMqttBridge $mqtt): array
    {
        return array_column($mqtt->ofType('temperature'), 'payload');
    }

    private static function frame(string $hex): string
    {
        return self::message('frame', ['char' => 'fff1', 'hex' => $hex]);
    }

    /** @param array<string, mixed> $payload */
    private static function message(string $kind, array $payload): string
    {
        return json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'joytech-node',
            'kind' => $kind,
            'device' => ['mac' => self::THERMOMETER, 'model' => 'DET-3018 '],
            'payload' => $payload,
            'tzOffsetMinutes' => 60,
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(RecordingHubMqttBridge $mqtt, ?DeviceStore $store = null): JoyTechThermometerBridge
    {
        return new JoyTechThermometerBridge(
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                self::THERMOMETER => IngressFixtures::device('JoyTech', 'HC-3018B', 'thermometer'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            'havicare-hub/null/0/gw/+/raw',
            $this->seen,
            $store,
            fn (): float => $this->now,
        );
    }
}
