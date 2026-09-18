<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\ConnectionInterface;
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
                0x811B => ['value' => "\x1C"],           // 28 compartimentos no total
                0x811D => ['value' => "\x10"],           // 16 restantes
            ],
        ]);

        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);
        $byFeature = [];
        foreach ($events as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertSame(['level' => 'low'], $byFeature['medication_level']);
        self::assertSame(['percent' => 80, 'chargingState' => 'charging'], $byFeature['battery']);
        self::assertSame(['environmentCelsius' => -5], $byFeature['temperature']);
        self::assertSame(['humidityPercent' => 47], $byFeature['humidity']);
        self::assertSame(['wifiSignalDbm' => -60, 'gsmSignalDbm' => -85], $byFeature['device_status']);
        self::assertSame(['remaining' => 16, 'total' => 28, 'current' => 12], $byFeature['cells_remaining']);
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
        self::assertSame(['state' => 'in_progress'], $byFeature['help_call']);
    }

    /** @param array<string, mixed> $payload */
    private function decode(array $payload): array
    {
        $adapter = new PillDispenserAdapter();
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing($payload));
        self::assertIsArray($decoded);

        return $decoded;
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

final class PillFakeConnection implements ConnectionInterface
{
    public int $resourceId = 1;

    public function remoteAddress(): ?string
    {
        return null;
    }

    public function send(string $data): static
    {
        return $this;
    }

    public function close(): static
    {
        return $this;
    }
}
