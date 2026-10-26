<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\Command\Downlink\RedisPendingDownlinkQueue;
use Hub\Device\HubMqttBridge;
use Hub\Ingress\Mqtt\Gateway\GatewayRelay;
use Hub\Ingress\Mqtt\JoyTech\BloodPressureHistoryMarks;
use Hub\Ingress\Mqtt\JoyTech\JoyTechBpBridge;
use Hub\Ingress\Mqtt\JoyTech\JoyTechFrame;
use Hub\Ingress\Mqtt\JoyTech\JoyTechOximeterBridge;
use Hub\Ingress\Mqtt\JoyTech\JoyTechThermometerBridge;
use Hub\Ingress\Mqtt\JoyTech\RecentMeasurementKeys;
use Hub\Ingress\Mqtt\Lefu\LefuTorreBridge;
use Hub\Ingress\Mqtt\Lefu\ScaleOnlineUsers;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use Hub\State\DeviceStore;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\InMemoryRedisClient;

/**
 * Prende, byte a byte, o que cada ponte GATT põe no fio e no histórico da dashboard. O que depende
 * do relógio de parede sai mascarado. `HUB_WRITE_TRANSCRIPTS=1` reescreve os ficheiros.
 */
final class GattBridgeTranscriptTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';
    private const FILTER = 'havicare-hub/null/0/gw/+/raw';

    private float $now;

    /** @var list<string> */
    private array $wire = [];

    private InMemoryRedisClient $redis;

    private DeviceStore $store;

    private HubMqttBridge $mqtt;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedisClient();
        $this->store = new DeviceStore($this->redis);
        $record = function (string $line): void {
            $this->wire[] = $line;
        };
        $this->mqtt = new HubMqttBridge(new class ($record) extends MqttClient {
            public function __construct(private \Closure $record)
            {
                parent::__construct('127.0.0.1', 1883, 'transcript');
            }

            public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
            {
                ($this->record)("{$topic} qos={$qualityOfService} retain=" . ($retain ? 1 : 0) . " {$message}");
            }
        }, 'havicare-hub');
    }

    public function testLefuTorre(): void
    {
        $this->now = (float)strtotime('2026-10-16T10:00:00Z');
        $scale = 'cfe908067329';
        $queue = new RedisPendingDownlinkQueue($this->redis);
        $queue->enqueue($scale, 'heartRateMeasurement', ['id' => 'op-1', 'command' => 'heartRateMeasurement', 'payload' => ['enabled' => true]], 300);
        $bridge = new LefuTorreBridge(
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                $scale => IngressFixtures::device('Unique Scales', 'HC-CF632BLE', 'scale'),
            ]),
            $this->mqtt,
            IngressFixtures::links(),
            $queue,
            self::FILTER,
            new ScaleOnlineUsers($this->redis),
            new RecentMeasurementKeys($this->redis, 'hub:lefu:history-seen', 500),
            $this->store,
            fn (): float => $this->now,
            scaleUsers: static fn (string $imei): array => [
                'items' => [['id' => 'u-0a1b2c3d', 'name' => 'Hugo', 'ageYears' => 23, 'sex' => 'male', 'heightCm' => 181, 'athlete' => false, 'weightKg' => 96]],
                'onlineUserId' => 'u-0a1b2c3d',
            ],
        );
        $message = static fn (string $kind, array $payload): string => json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'lefu-torre-node',
            'kind' => $kind,
            'device' => ['mac' => $scale, 'model' => 'CF632', 'firmware' => '005.015', 'rssiDbm' => -61],
            'payload' => $payload,
            'tzOffsetMinutes' => 90,
        ], JSON_THROW_ON_ERROR);
        $frame = static fn (string $char, string $hex): string => $message('frame', ['char' => $char, 'hex' => $hex]);

        $this->feed($bridge, [
            $message('session', ['connected' => true, 'battery' => 46, 'mtu' => 61]),
            $message('command_result', ['dedupeKey' => 'nope', 'ok' => true]),
            $frame('fff3', '010d6500942500000032a00f64330e'),
            $frame('fff3', '010d6500942500000032a00f64330e'),
            $frame('fff3', '020a180210536f00ff16db00'),
            $frame('fff3', '010da580942500 4a0032a00f64330e'),
            $frame('fff3', '010d3580fd25000000320000640000'),
            $frame('fff3', '010d0c00000000000000000000000000'),
            $frame('fff1', '06020101'),
            $frame('fff1', '07020200'),
            $frame('fff2', '05020204'),
            $frame('fff2', bin2hex(str_pad('u-0a1b2c3d', 64, "\0")) . 'ad3744691c074f0259746f00150fd1661e00290e' . 'b13744691c074f02' . str_repeat('00', 12)),
            $message('command_result', ['dedupeKey' => 'x', 'ok' => false, 'error' => 'not_connected']),
            $message('session', ['connected' => false]),
        ]);
        $this->now += 200;
        $bridge->dispatchQueued();

        $this->assertTranscript('lefu-torre', [$scale]);
    }

    public function testJoyTechBloodPressure(): void
    {
        $this->now = (float)strtotime('2026-10-16T13:51:40Z');
        $monitor = '748b34837b1e';
        $queue = new RedisPendingDownlinkQueue($this->redis);
        $bridge = new JoyTechBpBridge(
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                $monitor => IngressFixtures::device('JoyTech', 'HC-6292B', 'blood_pressure_monitor'),
            ]),
            $this->mqtt,
            IngressFixtures::links(),
            $queue,
            self::FILTER,
            new BloodPressureHistoryMarks($this->redis),
            $this->store,
            fn (): float => $this->now,
        );
        $message = static fn (string $kind, array $payload): string => json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'joytech-node',
            'kind' => $kind,
            'device' => ['mac' => $monitor, 'model' => 'DBP-6292B'],
            'payload' => $payload,
            'tzOffsetMinutes' => 60,
        ], JSON_THROW_ON_ERROR);
        $frame = static fn (string $hex): string => $message('frame', ['char' => 'fff1', 'hex' => str_replace(' ', '', $hex)]);

        $this->feed($bridge, [
            $message('session', ['connected' => true]),
            $frame('faaaaaaf0016830 07b0057590 01a050d092902 24f55f'),
            $frame('faaaaaaf001683008e004f58ff150101000101e6f55f'),
            $frame('faaaaaaf000a8b95f55f'),
        ]);
        $this->now += 10;
        $this->feed($bridge, [
            $frame('faaaaaaf00168300880049 4c001a0a100e33012cf55f'),
            $frame('faaaaaaf00168300880049 4c001a0a100e33012cf55f'),
        ]);
        $this->now += 16;
        $bridge->dispatchQueued();
        $queue->enqueue($monitor, 'readBloodPressureHistory', ['id' => 'op-2', 'command' => 'readBloodPressureHistory'], 300);
        $bridge->dispatchQueued();
        $this->feed($bridge, [
            $message('command_result', ['dedupeKey' => 'x', 'ok' => false, 'error' => 'not_connected']),
            $message('session', ['connected' => false]),
        ]);

        $this->assertTranscript('joytech-bp', [$monitor]);
    }

    public function testJoyTechEcg(): void
    {
        $this->now = (float)strtotime('2026-10-16T16:31:00Z');
        $monitor = '748b3483cda4';
        $bridge = new JoyTechBpBridge(
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                $monitor => IngressFixtures::device('JoyTech', 'HC-6679B', 'blood_pressure_monitor'),
            ]),
            $this->mqtt,
            IngressFixtures::links(),
            null,
            self::FILTER,
            new BloodPressureHistoryMarks($this->redis),
            $this->store,
            fn (): float => $this->now,
        );
        $lines = file(__DIR__ . '/JoyTech/ecg-dbp6679b.hex', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $start = $this->now;
        $first = (int)explode(' ', $lines[0])[0];
        foreach ($lines as $line) {
            [$ms, $hex] = explode(' ', $line);
            $this->now = $start + ((int)$ms - $first) / 1000;
            IngressFixtures::relay($bridge, self::TOPIC, (string)json_encode([
                'gatewayId' => self::GATEWAY,
                'source' => 'joytech-node',
                'kind' => 'frame',
                'device' => ['mac' => $monitor, 'model' => 'DBP-6679B'],
                'payload' => ['char' => 'fff1', 'hex' => $hex],
                'tzOffsetMinutes' => 60,
            ]));
        }
        // As 1500 tramas em cru já estão presas pelos outros; aqui interessa o que o exame produz.
        $this->wire = array_values(array_filter($this->wire, static fn (string $line): bool => !str_ends_with(strtok($line, ' '), '/raw')));

        $this->assertTranscript('joytech-ecg', [$monitor]);
    }

    public function testJoyTechOximeter(): void
    {
        $this->now = (float)strtotime('2026-10-19T13:30:00Z');
        $oximeter = '748b34838278';
        $bridge = new JoyTechOximeterBridge(
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                $oximeter => IngressFixtures::device('JoyTech', 'XM-101', 'pulse_oximeter'),
            ]),
            $this->mqtt,
            IngressFixtures::links(),
            $this->store,
            fn (): float => $this->now,
        );
        $message = static fn (string $kind, array $payload): string => json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'joytech-node',
            'kind' => $kind,
            'device' => ['mac' => $oximeter, 'model' => 'XM-101'],
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        IngressFixtures::relay($bridge, self::TOPIC, $message('session', ['connected' => true]));
        foreach ([[99, 66, 30], [98, 83, 40], [97, 87, 10]] as [$spo2, $pulse, $frames]) {
            for ($i = 0; $i < $frames; $i++) {
                IngressFixtures::relay($bridge, self::TOPIC, $message('frame', ['char' => 'fff1', 'hex' => JoyTechFrame::encode(0x88, pack('C5', $spo2, $pulse, 0, 9, 64))]));
                $this->now += 0.1;
            }
        }
        $this->now += 6;
        $bridge->dispatchQueued();
        for ($i = 0; $i < 40; $i++) {
            IngressFixtures::relay($bridge, self::TOPIC, $message('frame', ['char' => 'fff1', 'hex' => 'faaaaaaf000f88625400094fa5f55f']));
            $this->now += 0.1;
        }
        IngressFixtures::relay($bridge, self::TOPIC, $message('session', ['connected' => false]));

        $this->assertTranscript('joytech-oximeter', [$oximeter]);
    }

    public function testJoyTechThermometer(): void
    {
        $this->now = (float)strtotime('2026-10-16T15:43:00Z');
        $thermometer = '748b34830e5b';
        $bridge = new JoyTechThermometerBridge(
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                $thermometer => IngressFixtures::device('JoyTech', 'DET-3018B', 'thermometer'),
            ]),
            $this->mqtt,
            IngressFixtures::links(),
            self::FILTER,
            new RecentMeasurementKeys($this->redis),
            $this->store,
            fn (): float => $this->now,
        );
        $message = static fn (string $kind, array $payload): string => json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'joytech-node',
            'kind' => $kind,
            'device' => ['mac' => $thermometer, 'model' => 'DET-3018B'],
            'payload' => $payload,
            'tzOffsetMinutes' => 60,
        ], JSON_THROW_ON_ERROR);
        $frame = static fn (string $hex): string => $message('frame', ['char' => 'fff1', 'hex' => $hex]);

        IngressFixtures::relay($bridge, self::TOPIC, $message('session', ['connected' => true]));
        foreach ([0.25, 0.25, 0.25, 3.0] as $step) {
            $this->now += $step;
            IngressFixtures::relay($bridge, self::TOPIC, $frame('faaaaaaf000a0610f55f'));
        }
        IngressFixtures::relay($bridge, self::TOPIC, $frame('faaaaaaf00fa03020e491a0a10102a020e431a0a10102a020e361a0a0f0f12020e451a0a090c1e020e371a0a090c1d020e291a0a08091f020e431a0a010d29020e411a09041102020e481a08120a3a020e521a08120a39020dfd1a0811091c020e101a0811091c020dbd1a07180c16020dfd1a07180c16020e061a07180c16020e441a070b0b06020e1d1a07060c38020e201a07060c38020e2b1a07060c38020e381a07060c38020e261a07060c29020e311a07060c29020e3d1a061a0d08020e3e1a061a0d07020e2f1a061a0d07020e1b1a061a0c05020e1a180101153b020e1d1801011537020da81a06180c29020e141a06180c2915f55f'));
        IngressFixtures::relay($bridge, self::TOPIC, $message('session', ['connected' => false]));

        $this->assertTranscript('joytech-thermometer', [$thermometer]);
    }

    public function testVeepoo(): void
    {
        $this->now = (float)strtotime('2026-09-09T16:40:00Z');
        $bracelet = '9f69c4866e6c';
        $queue = new RedisPendingDownlinkQueue($this->redis);
        $queue->enqueue($bracelet, 'measure.heartRate.start', ['id' => 'op-3', 'command' => 'measure.heartRate.start'], 300);
        $queue->enqueue($bracelet, 'config:find_device', ['id' => 'op-4', 'command' => 'config:find_device', 'payload' => ['enabled' => true]], 300);
        $bridge = new VeepooBridge(
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                $bracelet => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $this->mqtt,
            IngressFixtures::links(),
            $queue,
            new ArrayObservationStateStore(),
            self::FILTER,
            $this->store,
            fn (): float => $this->now,
        );
        $message = static fn (string $kind, mixed $payload): string => json_encode([
            'source' => 'veepoo-node',
            'kind' => $kind,
            'device' => ['mac' => $bracelet, 'firmware' => '0.0.1.2', 'rssiDbm' => -70],
            'payload' => $payload,
            'tzOffsetMinutes' => 60,
        ], JSON_THROW_ON_ERROR);

        $this->feed($bridge, [
            $message('session', ['connected' => true, 'authenticated' => true]),
            $message('battery', ['VPDeviceElectricPercent' => 12, 'VPDeviceIsPercent' => true, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']),
            $message('measurement', ['sdkType' => 51, 'heartRate' => 0]),
            $message('measurement', ['sdkType' => 51, 'heartRate' => 72]),
            $message('measurement', ['sdkType' => 51, 'heartRate' => 74]),
            $message('command_result', ['operation' => 'measure.heartRate.start', 'ok' => true, 'dedupeKey' => 'raw:' . hash('sha256', 'measure.heartRate.start')]),
            $message('measurement', ['sdkType' => 9, 'step' => 1234, 'distance' => 800, 'calorie' => 456]),
            $message('measurement', ['sdkType' => 17, 'value' => 'search']),
            $message('measurement', ['sdkType' => 31, 'notWear' => true]),
            $message('measurement', ['sdkType' => 99]),
            $message('command_result', ['operation' => 'config:find_device', 'ok' => false, 'error' => 'busy']),
            $message('ecg_wave', ['samples' => [1, -2, 3, 0, 5], 'samplingHz' => 250, 'status' => [['HR2PerMinute' => 70, 'Hrv' => 40, 'QTC' => 400, 'RR1PerSecond' => 85]]]),
            $message('ecg_wave', ['samples' => [0, 0, 0]]),
            $message('sleep', [[
                'fallAsleepTime' => '09-16-01-00',
                'exitSleepTime' => '09-16-01-10',
                'sleepQuality' => 2,
                'deepSleepTime' => 2,
                'lightSleepTime' => 6,
                'otherSleepTime' => 2,
                'sleepTotalTime' => 10,
                'sleepCurve' => [0, 0, 1, 1, 1, 1, 2, 2, 1, 1],
            ]]),
            $message('daily_block', [['date' => '2026-09-09-16-35', 'pulseReat' => [70, 71, 72, 73, 74]]]),
            $message('daily_block', ['date' => '2026-09-09-16-35', 'pulseReat' => [70, 71, 72, 73, 74]]),
            $message('mystery', []),
            $message('session', ['connected' => false]),
        ]);
        $this->now += 30;
        $bridge->dispatchQueued();

        $this->assertTranscript('veepoo', [$bracelet]);
    }

    /** @param list<string> $messages */
    private function feed(GatewayRelay $bridge, array $messages): void
    {
        foreach ($messages as $message) {
            IngressFixtures::relay($bridge, self::TOPIC, $message);
            $this->now += 0.5;
        }
    }

    /** @param list<string> $deviceKeys */
    private function assertTranscript(string $name, array $deviceKeys): void
    {
        $lines = $this->wire;
        foreach ($deviceKeys as $deviceKey) {
            foreach (['telemetry', 'events'] as $list) {
                foreach ($this->store->recent($deviceKey, $list) as $entry) {
                    $lines[] = "dashboard {$deviceKey} {$list} " . json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
        }
        $transcript = implode("\n", array_map(self::masked(...), $lines)) . "\n";

        $file = __DIR__ . "/transcripts/{$name}.txt";
        if (getenv('HUB_WRITE_TRANSCRIPTS') === '1') {
            file_put_contents($file, $transcript);
        }
        self::assertFileExists($file);
        self::assertSame(file_get_contents($file), $transcript);
    }

    /** O relógio de parede: as horas de agora, os prazos e o acerto do relógio do aparelho. */
    private static function masked(string $line): string
    {
        $now = time();
        $line = (string)preg_replace_callback(
            '/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)/',
            static fn (array $m): string => abs((int)strtotime($m[0]) - $now) < 600 ? '<now>' : $m[0],
            $line,
        );
        $line = (string)preg_replace_callback(
            '/(?<!\d)\d{10}(?:\.\d+)?(?!\d)/',
            static fn (array $m): string => abs((int)$m[0] - $now) < 86400 ? '<now>' : $m[0],
            $line,
        );
        if (preg_match('/"operation":"(calibrateClock|answerClock)"/', $line) === 1) {
            $line = (string)preg_replace('/"hex":"[0-9a-f]+"/', '"hex":"<clock>"', $line);
        }

        return $line;
    }
}
