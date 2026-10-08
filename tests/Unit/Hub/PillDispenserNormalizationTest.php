<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

final class PillDispenserNormalizationTest extends TestCase
{
    public function testMedicationEventBecomesMedicationIntake(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x03,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0xC201 => ['value' => "\x02"],                // alarme 3 (0-8)
                0xC202 => ['value' => '2026-09-18T20:05:00'],
                0xC203 => ['value' => '2026-09-18T20:05:04'],
                0xC204 => ['value' => "\x0C"],                // célula 12
                0xC205 => ['value' => "\x00"],                // método: a horas
                0xC206 => ['value' => "\x03"],                // resultado: falhada
            ],
        ]);

        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);

        self::assertCount(1, $events);
        self::assertSame('medication_intake', $events[0]['feature']);
        self::assertSame([
            'alarmSlot' => 3,
            'scheduledAt' => '2026-09-18T20:05:00',
            'takenAt' => '2026-09-18T20:05:04',
            'cellNumber' => 12,
            'method' => 'on_time',
            'result' => 'missed',
        ], $events[0]['value']);
    }

    public function testHeartbeatStatusBecomesTelemetry(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x02,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8101 => ['value' => "\x01"],           // medicação a acabar
                0x8103 => ['value' => "\x50"],           // bateria 80%
                0x8104 => ['value' => "\x03"],           // a carregar
                0x810E => ['value' => pack('c', -5)],    // −5 °C, INT8S de um byte
                0x810F => ['value' => pack('C', 47)],    // 47 %RH, INT8U de um byte
                0x810A => ['value' => pack('s', -60)],   // WiFi -60 dBm, INT16S
                0x810B => ['value' => pack('s', -85)],   // GSM -85 dBm, INT16S
                0x811A => ['value' => "\x0C"],           // compartimento atual 12
                0x811B => ['value' => "\x1D"],           // 29 posições, que são 28 compartimentos
                0x811D => ['value' => "\x10"],           // 16 restantes
            ],
        ]);

        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);
        $byFeature = [];
        foreach ($events as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertSame('low', $byFeature['cells_remaining']['level'] ?? null);
        self::assertSame(['percent' => 80, 'chargingState' => 'charging', 'lowBattery' => false], $byFeature['battery']);
        self::assertSame(['environmentCelsius' => -5], $byFeature['ambient_temperature']);
        self::assertSame(['humidityPercent' => 47], $byFeature['ambient_humidity']);
        // Com os dois rádios a reportar, a interface é aquela por onde ele está mesmo a
        // falar: nesta unidade é o móvel, e o WiFi nem sequer existe.
        self::assertSame(['interface' => 'cellular', 'signalStrengthDbm' => -85], $byFeature['connectivity']);
        self::assertSame(['remaining' => 16, 'total' => 28, 'current' => 12, 'level' => 'low'], $byFeature['cells_remaining']);
    }

    public function testTheConfigurationReadBackBecomesADeviceConfigEvent(): void
    {
        // A resposta ao `0x05` traz o corpo pedido já preenchido. Sem a ler, o hub saberia o
        // que pediu ao aparelho e nunca o que ele tem.
        $decoded = $this->decode([
            'packetType' => 0x85,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x1021 => ['value' => "\x08"],          // alarme 1 às 08:30, ligado
                0x1031 => ['value' => "\x1E"],
                0x1041 => ['value' => "\x01"],
                0x1042 => ['value' => "\x00"],          // alarme 2 desligado
                0x1013 => ['value' => "\x01"],          // volume médio
                0x1012 => ['value' => "\x02"],          // toque 2
                0x1001 => ['value' => "\x01"],          // inglês
                0x1015 => ['value' => pack('s', 100)],  // Lisboa no verão
                0x100C => ['value' => "\x01"],          // bloqueio de criança ligado
                0x100D => ['value' => "\x00"],
            ],
        ]);

        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);

        self::assertCount(1, $events);
        self::assertSame('device_config', $events[0]['feature']);
        // Cada configuração volta pela chave do contrato e com a forma com que é enviada: é o
        // que permite guardá-la como reportada e compará-la com o desejado sem traduzir.
        $settings = $events[0]['value']['settings'];
        self::assertSame(['volume' => 1], $settings['alarm_volume']);
        self::assertSame(['ringtone' => 2], $settings['alarm_ringtone']);
        self::assertSame(['language' => 1], $settings['device_language']);
        self::assertSame(['timeZone' => 100], $settings['time_zone']);
        self::assertSame(['enabled' => true], $settings['child_lock']);
        self::assertSame(['enabled' => false], $settings['early_dispense']);
        // O plano volta só com os alarmes definidos: os outros oito seriam ruído.
        self::assertSame(
            ['plans' => [['slot' => 1, 'hour' => 8, 'minute' => 30]]],
            $settings['medication_reminders'],
        );
    }

    public function testAStatusQueryAnswerIsReadLikeAHeartbeat(): void
    {
        // A resposta ao `0x07` traz as mesmas TAGs de estado que o heartbeat, e por isso é
        // lida pelo mesmo caminho -- ter dois seria ter duas verdades.
        $decoded = $this->decode([
            'packetType' => 0x87,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8103 => ['value' => "\x50"],
                0x810E => ['value' => pack('c', 21)],
            ],
        ]);

        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);
        $byFeature = [];
        foreach ($events as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertSame(['percent' => 80], $byFeature['battery']);
        self::assertSame(['environmentCelsius' => 21], $byFeature['ambient_temperature']);
    }

    public function testARefusedWriteIsVisibleInTheAnswer(): void
    {
        // O aparelho responde ao `0x06` com o mesmo corpo e o resultado de cada TAG nos bits
        // de estado do Flag.
        $adapter = new PillDispenserAdapter();
        $frame = $adapter->encodeOutgoing([
            'packetType' => 0x86,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x1021 => ['value' => "\x08", 'state' => 0],
                0x1031 => ['value' => "\x1E", 'state' => 4],
            ],
        ]);
        $decoded = $adapter->decodeIncoming($frame);
        self::assertIsArray($decoded);

        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);

        self::assertSame('device_config', $events[0]['feature']);
        self::assertSame(['0x1031'], $events[0]['value']['refusedTags']);
    }

    public function testFaultAndEmergencyBecomeEvents(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x02,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8122 => ['value' => "\x01"],   // reset do prato em falha
                0x8112 => ['value' => "\x01"],   // chamada de emergência em curso
            ],
        ]);

        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);
        $byFeature = [];
        foreach ($events as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertSame(['fault' => 'tray_reset'], $byFeature['device_fault']);
        self::assertSame([], $byFeature['help_call']);
    }

    /**
     * O `0x810B` é o CSQ do módulo e sai como a `connectivity` dos gateways; o `0x810D` são
     * barras de 0 a 3, que não cabem no `signalQuality` de 0 a 31.
     */
    public function testTheStatusQueryBringsTheSignalAsConnectivity(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x07 | 0x80,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8102 => ['value' => "\x00", 'state' => 0],       // destrancado
                // O aparelho manda a magnitude; o sinal é nosso, porque um sinal recebido é
                // sempre negativo e o fornecedor confirmou-o.
                0x810B => ['value' => "\x19\x00", 'state' => 0],   // 25
                0x810D => ['value' => "\x03", 'state' => 0],       // três barras
            ],
        ]);

        $events = [];
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $events[$event['feature']] = $event['value'];
        }

        self::assertSame([
            'interface' => 'cellular',
            'signalStrengthDbm' => -63,
        ], $events['connectivity'] ?? null);
    }

    /**
     * O `0x8102` é o que o aparelho tem do que o `0x100C` pede: volta como valor reportado da
     * configuração, e não como telemetria ao lado dela.
     */
    public function testTheLockStateComesBackAsTheReportedConfiguration(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x07 | 0x80,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [0x8102 => ['value' => "\x01", 'state' => 0]],
        ]);

        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);

        self::assertSame('device_config', $events[0]['feature']);
        self::assertSame(['enabled' => true], $events[0]['value']['settings']['child_lock']);
    }

    /**
     * Uma TAG recusada não tem valor: o que lá está é o eco do que mandámos, e é o estado no Flag
     * que diz se há valor.
     */
    public function testARefusedTagIsNotReadAsAValue(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x07 | 0x80,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8103 => ['value' => "\x63", 'state' => 0],             // bateria, 99%
                0x810A => ['value' => "\x00\x00", 'state' => 1],         // WiFi: recusada
                0x810B => ['value' => "\x18\x00", 'state' => 0],         // GSM
                0x810E => ['value' => "\x1B", 'state' => 0],             // 27 °C
                0x810F => ['value' => "\x00", 'state' => 1],             // humidade: recusada
            ],
        ]);

        $events = [];
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $events[$event['feature']] = $event['value'];
        }

        self::assertSame(['environmentCelsius' => 27], $events['ambient_temperature'] ?? null);
        self::assertArrayNotHasKey('ambient_humidity', $events, 'uma TAG recusada não vira telemetria');
        // O WiFi foi recusado e o móvel não: a ligação é a que respondeu, e o zero do eco não
        // pode passar por uma leitura de 0 dBm.
        self::assertSame(
            ['interface' => 'cellular', 'signalStrengthDbm' => -65],
            $events['connectivity'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function decode(array $payload): array
    {
        $adapter = new PillDispenserAdapter();
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing($payload));
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** O `0x811B` conta posições e não compartimentos: a zero é a de repouso. */
    public function testTheTrayCapacityLeavesOutTheRestingPosition(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x87,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x811A => ['value' => "\x10"],
                0x811B => ['value' => "\x1D"],
                0x811D => ['value' => "\x00"],
            ],
        ]);

        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);
        $cells = [];
        foreach ($events as $event) {
            if ($event['feature'] === 'cells_remaining') {
                $cells = $event['value'];
            }
        }

        self::assertSame(28, $cells['total'] ?? null);
        self::assertSame(16, $cells['current'] ?? null);
        self::assertSame(0, $cells['remaining'] ?? null);
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
            'tcp',
            true,
            'AABBCCDDEEFF',
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }
}
