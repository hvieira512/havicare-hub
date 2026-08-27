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
 * O alarme `change_required` do medidor de fraldas MONIT MECS-PRO.
 *
 * O caminho da telemetria está coberto pelo `BridgeTest`; este cobre o dos eventos, que é
 * onde um alarme de primeira observação se pode perder sem ninguém dar por isso.
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

        // Três leituras molhadas distintas: a deduplicação de observações cruas é por
        // payload, por isso estas são três observações reais e não uma repetição.
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
        // Três canais molhados: no preset normal são 3 de 4 exigidos, portanto `attention`.
        // Apertar para "mais alertas" (3 canais, delta 7) torna a MESMA leitura numa muda
        // necessaria -- e o alarme tem de tocar, senao apertar a sensibilidade numa fralda
        // já suja não produz nada.
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
        // Com a sensibilidade na CHAVE do estado em vez de no valor, voltar a um preset já
        // usado reencontrava o `change_required` antigo, não via transição, e a fralda suja
        // ficava sem alarme.
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
        // A sensibilidade vive dentro do estado guardado para que uma alteração conte como
        // transição. O `previousState` é contrato publicado e continua a ser um dos três
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
        // bridge -- a sensibilidade é a do preset normal. É o que garante que um sensor que
        // ninguém configurou continua a comportar-se como sempre se comportou.
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('three_wet'));
        self::assertCount(0, $this->alarms($mqtt));

        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));
        self::assertCount(1, $this->alarms($mqtt));
    }

    /**
     * Só os alarmes da fralda. O gateway publica o seu `device.connected` na primeira
     * mensagem em que é visto, e não é isso que estes testes medem.
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

    /** Constrói a mensagem de scan BLE do gateway que leva um anúncio MECS-PRO. */
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
     * O payload de 20 bytes do fabricante, construído para uma condição pretendida.
     *
     * Quem decide é o normalizador: delta máximo < 4 é `clean`, quatro ou mais canais com
     * delta >= 12 é `change_required`, e o que fica entre os dois é `attention`.
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
        // O descodificador exige que os últimos três bytes repitam a cauda do MAC.
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
