<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\PillDispenserFixtures;

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

        $events = (new DeviceEventDecoder())->decode(PillDispenserFixtures::session(), $decoded);

        self::assertCount(1, $events);
        self::assertSame('medication_intake', $events[0]['feature']);
        self::assertSame([
            'slot' => 3,
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

        $events = (new DeviceEventDecoder())->decode(PillDispenserFixtures::session(), $decoded);
        $byFeature = [];
        foreach ($events as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertSame('low', $byFeature['cells_remaining']['level'] ?? null);
        self::assertSame(['percent' => 80, 'charging' => true, 'lowBattery' => false], $byFeature['battery']);
        self::assertSame(['environmentCelsius' => -5], $byFeature['ambient_temperature']);
        self::assertSame(['humidityPercent' => 47], $byFeature['ambient_humidity']);
        // Com os dois rádios a reportar, a interface é aquela por onde ele está mesmo a
        // falar: nesta unidade é o móvel, e o WiFi nem sequer existe.
        self::assertSame(['interface' => 'cellular', 'signalStrengthDbm' => -85], $byFeature['connectivity']);
        self::assertSame(['remaining' => 16, 'total' => 28, 'current' => 12, 'level' => 'low'], $byFeature['cells_remaining']);
    }

    /** O estado das condições sai a cada heartbeat, acesas ou não: é por ele que se vê que apagaram. */
    public function testHeartbeatConditionsBecomeTelemetry(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x02,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8111 => ['value' => "\x00"],
                0x8112 => ['value' => "\x00"],
                0x8121 => ['value' => "\x00"],
                0x8122 => ['value' => "\x00"],
                0x8123 => ['value' => "\x01"],
                0x8124 => ['value' => "\x00"],
                0x8125 => ['value' => "\x01"],
            ],
        ]);

        $conditions = $this->featureValues($decoded, 'dispenser_conditions');

        self::assertSame([['faults' => ['pusher', 'keys'], 'storageOutOfRange' => false, 'helpCallActive' => false]], $conditions);
    }

    public function testAllClearConditionsSayNoFault(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x02,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [0x8111 => ['value' => "\x01"], 0x8121 => ['value' => "\x00"]],
        ]);

        self::assertSame([['faults' => [], 'storageOutOfRange' => true]], $this->featureValues($decoded, 'dispenser_conditions'));
    }

    public function testAPacketWithoutConditionTagsSaysNothingAboutThem(): void
    {
        $decoded = $this->decode([
            'packetType' => 0x02,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [0x8103 => ['value' => "\x50"]],
        ]);

        self::assertSame([], $this->featureValues($decoded, 'dispenser_conditions'));
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<mixed>
     */
    private function featureValues(array $decoded, string $feature): array
    {
        $events = (new DeviceEventDecoder())->decode(PillDispenserFixtures::session(), $decoded);

        return array_column(array_values(array_filter($events, static fn (array $event): bool => $event['feature'] === $feature)), 'value');
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

        $events = (new DeviceEventDecoder())->decode(PillDispenserFixtures::session(), $decoded);
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

        $events = (new DeviceEventDecoder())->decode(PillDispenserFixtures::session(), $decoded);

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

        $events = (new DeviceEventDecoder())->decode(PillDispenserFixtures::session(), $decoded);
        $byFeature = [];
        foreach ($events as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertSame(['fault' => 'tray_reset'], $byFeature['device_fault']);
        self::assertSame([], $byFeature['help_call']);
    }

    /** Uma TAG recusada traz o eco do que mandámos, e é o estado no Flag que diz se há valor. */
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
        foreach ((new DeviceEventDecoder())->decode(PillDispenserFixtures::session(), $decoded) as $event) {
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
}
