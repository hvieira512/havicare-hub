<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\ConnectionInterface;
use Hub\Device\DeviceHubServer;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O M228 apaga o evento quando recebe a confirmação: ela só sai com o evento publicado. */
final class PillDispenserAckAfterPublishTest extends TestCase
{
    private const IMEI = '869243062262262';

    public function testAnIntakeThatWasNotPublishedIsNotAcknowledged(): void
    {
        $mqtt = new class extends RecordingHubMqttBridge {
            public function publishEvent(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
            {
                if (($payload['type'] ?? '') === 'medication_alarm_change') {
                    throw new \RuntimeException('broker unavailable');
                }
                parent::publishEvent($imei, $payload, $deviceType, $licenseId, $company);
            }
        };

        self::assertSame(['register_ack'], $this->acks($mqtt, [$this->frame(0x01, 1), $this->frame(0x04, 2, [0x8135 => ['value' => "\x06"]])]));
    }

    public function testAPublishedEventIsAcknowledged(): void
    {
        self::assertSame(
            ['register_ack', 'change_ack'],
            $this->acks(new RecordingHubMqttBridge(), [$this->frame(0x01, 1), $this->frame(0x04, 2, [0x8135 => ['value' => "\x06"]])]),
        );
    }

    /** Um corpo que não se decifra não chega a ser lido: confirmá-lo apagava-o sem ninguém o ver. */
    public function testAnEventThatDoesNotDecryptIsNotAcknowledged(): void
    {
        $noise = str_repeat("\x5A", 32);

        self::assertSame(['register_ack'], $this->acks(new RecordingHubMqttBridge(), [
            $this->frame(0x01, 1),
            (new PillDispenserAdapter())->encodeOutgoing(['packetType' => 0x03, 'serial' => 2, 'imei' => self::IMEI, 'tlv' => [], 'flag' => 0x04, 'appDataRaw' => $noise]),
        ]));
    }

    /**
     * @param list<string> $frames
     * @return list<string>
     */
    private function acks(RecordingHubMqttBridge $mqtt, array $frames): array
    {
        $connection = new class implements ConnectionInterface {
            public int $resourceId = 1;

            /** @var list<string> */
            public array $sent = [];

            public function remoteAddress(): ?string
            {
                return null;
            }

            public function send(string $data): static
            {
                $this->sent[] = $data;
                return $this;
            }

            public function close(): static
            {
                return $this;
            }
        };
        $hub = new DeviceHubServer(IngressFixtures::whitelist([self::IMEI => IngressFixtures::device('Zayata', 'M228', 'pill_dispenser')]), $mqtt);
        $hub->onOpen($connection);
        foreach ($frames as $frame) {
            $hub->onMessage($connection, $frame);
        }

        return array_map(
            static fn (string $bytes): string => (string)((new PillDispenserAdapter())->decodeIncoming($bytes)['type'] ?? '?'),
            $connection->sent,
        );
    }

    /** @param array<int, array{value: string}> $tlv */
    private function frame(int $packetType, int $serial, array $tlv = []): string
    {
        return (new PillDispenserAdapter())->encodeOutgoing(['packetType' => $packetType, 'serial' => $serial, 'imei' => self::IMEI, 'tlv' => $tlv]);
    }
}
