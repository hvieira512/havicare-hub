<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Domain\DiaperSensitivity;
use Hub\Domain\DiaperSensitivityLookup;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Moko\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\MutableDiaperSensitivity;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\FakeMqttSubscriber;

/**
 * The `change_required` alarm of the MONIT MECS-PRO diaper sensor.
 *
 * The telemetry path was covered by BridgeTest, but the event path was not, which is
 * how a first-observation alarm could be swallowed unnoticed.
 */
final class BridgeMonitAlarmTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const SENSOR = 'eec5000202f9';

    public function testFirstObservationOfAWetSensorStillRaisesTheAlarm(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));

        $alarms = $this->alarms($mqtt);
        self::assertCount(1, $alarms);
        self::assertNull(
            $alarms[0]['payload']['data']['previousState'],
            'A first observation has no previous state, and must say so rather than be dropped.'
        );
    }

    public function testUnchangedConditionRaisesTheAlarmOnlyOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        // Three distinct wet readings: the dedupe of raw observations is keyed on the
        // payload, so these are three real observations and not a replay.
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 79));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 78));

        self::assertCount(1, $this->alarms($mqtt));
    }

    public function testTransitionFromAttentionCarriesThePreviousState(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('attention'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));

        $alarms = $this->alarms($mqtt);
        self::assertCount(1, $alarms);
        self::assertSame('attention', $alarms[0]['payload']['data']['previousState']);
    }

    public function testRecoveringAndWettingAgainRaisesASecondAlarm(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('clean'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 79));

        $alarms = $this->alarms($mqtt);
        self::assertCount(2, $alarms);
        self::assertNull($alarms[0]['payload']['data']['previousState']);
        self::assertSame('clean', $alarms[1]['payload']['data']['previousState']);
    }

    public function testACleanFirstObservationRaisesNoAlarm(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage($this->topic(), $this->scan('clean'));

        self::assertSame([], $this->alarms($mqtt));
        self::assertSame(
            ['proximity', 'battery', 'diaper_moisture', 'diaper_moisture_level', 'diaper_condition'],
            array_column($mqtt->telemetry, 'type')
        );
    }

    public function testTighteningTheSensitivityRaisesTheAlarmForTheSameReading(): void
    {
        // Tres canais molhados: no preset normal sao 3 de 4 exigidos, portanto `attention`.
        // Apertar para "mais alertas" (3 canais, delta 7) torna a MESMA leitura numa muda
        // necessaria -- e o alarme tem de tocar, senao apertar a sensibilidade numa fralda
        // ja suja nao produz nada.
        $mqtt = new RecordingHubMqttBridge();
        $sensitivity = new MutableDiaperSensitivity();
        $bridge = $this->bridge($mqtt, $sensitivity);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('three_wet'));
        self::assertCount(0, $this->alarms($mqtt), 'No preset normal isto e attention.');

        $sensitivity->settings = DiaperSensitivity::PRESETS['high'];
        $bridge->handleReceivedMessage($this->topic(), $this->scan('three_wet', battery: 79));

        $alarms = $this->alarms($mqtt);
        self::assertCount(1, $alarms);
        self::assertSame('attention', $alarms[0]['payload']['data']['previousState']);
    }

    public function testLooseningAndTighteningAgainDoesNotSwallowTheAlarm(): void
    {
        // O caso que a primeira versao desta feature falhava. Com a sensibilidade na CHAVE do
        // estado em vez de no valor, voltar a um preset ja usado reencontrava o
        // `change_required` antigo, nao via transicao, e a fralda suja ficava sem alarme.
        $mqtt = new RecordingHubMqttBridge();
        $sensitivity = new MutableDiaperSensitivity();
        $bridge = $this->bridge($mqtt, $sensitivity);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));
        self::assertCount(1, $this->alarms($mqtt));

        $sensitivity->settings = DiaperSensitivity::PRESETS['low'];
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 79));
        self::assertCount(1, $this->alarms($mqtt), 'Com menos alertas a mesma leitura e attention.');

        $sensitivity->settings = DiaperSensitivity::PRESETS['normal'];
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 78));

        $alarms = $this->alarms($mqtt);
        self::assertCount(2, $alarms, 'Voltar ao preset anterior tem de reavaliar, nao recordar.');
        self::assertSame('attention', $alarms[1]['payload']['data']['previousState']);
    }

    public function testTheSensitivityNeverLeaksIntoThePublishedEvent(): void
    {
        // A sensibilidade vive dentro do estado guardado para que uma alteracao conte como
        // transicao. O `previousState` e contrato publicado e continua a ser um dos tres
        // estados, ou nulo -- nunca "attention@7-15".
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt, new MutableDiaperSensitivity());

        $bridge->handleReceivedMessage($this->topic(), $this->scan('attention'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));

        $previous = $this->alarms($mqtt)[0]['payload']['data']['previousState'];
        self::assertSame('attention', $previous);
        self::assertStringNotContainsString('@', (string)$previous);
    }

    public function testWithoutALookupTheHubKeepsItsHistoricalThresholds(): void
    {
        // Sem lookup ligado -- que e como todos os outros testes deste ficheiro constroem o
        // bridge -- a sensibilidade e a do preset normal, que sao os limiares que o hub tinha
        // em hardcode. Isto e o que garante que ligar a feature nao mexeu em producao.
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('three_wet'));
        self::assertCount(0, $this->alarms($mqtt));

        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));
        self::assertCount(1, $this->alarms($mqtt));
    }

    /**
     * The diaper alarms only. The gateway publishes its own `device.connected` on the
     * first message it is seen on, and that is not what these tests measure.
     *
     * @return list<array<string, mixed>>
     */
    private function alarms(RecordingHubMqttBridge $mqtt): array
    {
        return array_values(array_filter(
            $mqtt->events,
            static fn(array $event): bool => ($event['type'] ?? '') === 'change_required'
        ));
    }

    private function topic(): string
    {
        return 'havicare-hub/null/0/gw/' . self::GATEWAY . '/raw';
    }

    /** Builds the gateway BLE-scan message that carries one MECS-PRO advertisement. */
    private function scan(string $condition, int $battery = 80): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [[
                'adv_data' => $this->advertisement($condition, $battery),
                'rsp_data' => '0f094d4f4e4954204d4543532050524f',
                'type_code' => 10, 'type' => 'other', 'rssi' => -83,
                'connectable' => 0, 'mac' => self::SENSOR,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The 20-byte manufacturer payload, built for a wanted condition.
     *
     * The normalizer decides: maximum delta < 4 is clean, four or more channels at
     * delta >= 12 is change_required, anything between is attention.
     */
    private function advertisement(string $condition, int $battery): string
    {
        $baseline = array_fill(0, 10, 1);
        $raw = match ($condition) {
            'clean' => array_fill(0, 10, 2),                        // delta 1
            'attention' => array_fill(0, 10, 7),                    // delta 6, nenhum >= 12
            'change_required' => [13, 13, 13, 13, 1, 1, 1, 1, 1, 1], // 4 canais a delta 12
            'three_wet' => [13, 13, 13, 1, 1, 1, 1, 1, 1, 1],           // 3 canais a delta 12
            default => throw new \InvalidArgumentException($condition),
        };

        $bits = str_pad(decbin(0), 3, '0', STR_PAD_LEFT)            // packetType
            . str_pad(decbin($battery), 7, '0', STR_PAD_LEFT)       // batteryPercent
            . '0' . '00' . '000';                                    // alarmType, txStrength, eventStatus
        foreach ([...$baseline, ...$raw] as $value) {
            $bits .= str_pad(decbin($value), 6, '0', STR_PAD_LEFT);
        }
        // The decoder requires the last three bytes to repeat the tail of the MAC.
        foreach ([0x02, 0x02, 0xf9] as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $raw20 = '';
        for ($offset = 0; $offset < 160; $offset += 8) {
            $raw20 .= sprintf('%02x', bindec(substr($bits, $offset, 8)));
        }

        $manufacturer = '59000215' . $raw20 . 'c3';
        return '020104' . sprintf('%02x', strlen($manufacturer) / 2 + 1) . 'ff' . $manufacturer;
    }

    private function bridge(RecordingHubMqttBridge $mqtt, ?DiaperSensitivityLookup $sensitivity = null): Bridge
    {
        $path = tempnam(sys_get_temp_dir(), 'moko-whitelist-');
        file_put_contents($path, json_encode([
            self::GATEWAY => ['supplier' => 'MOKO', 'model' => 'MKGW3', 'deviceType' => 'gateway', 'licenseId' => '1001', 'company' => 'hitcare'],
            self::SENSOR => ['supplier' => 'MONIT', 'model' => 'MECS-PRO', 'deviceType' => 'diaper_sensor', 'licenseId' => '1001', 'company' => 'hitcare'],
        ], JSON_THROW_ON_ERROR));

        $links = new class implements GatewayDeviceLinkLookup {
            public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool
            {
                return true;
            }
        };

        return new Bridge(
            new FakeMqttSubscriber(),
            new Whitelist($path),
            $mqtt,
            $links,
            new ArrayObservationStateStore(),
            diaperSensitivity: $sensitivity,
        );
    }

}
