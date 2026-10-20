<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\ConnectionInterface;
use Hub\Device\DeviceHubServer;
use Hub\Device\RedisPendingDownlinkQueue;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\State\DeviceStore;
use Tests\Support\Doubles\InMemoryFirmwareUpgradeStore;
use Tests\Support\Doubles\InMemoryRedisClient;
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

    /**
     * Um corpo que não se decifra dá o mesmo a cada repetição, e prendia os eventos de trás: confirma-se
     * depois de os bytes ficarem no canal `raw`, para se decifrarem mais tarde.
     */
    public function testAnEventThatDoesNotDecryptIsAcknowledgedOnceItsBytesAreInRaw(): void
    {
        $mqtt = new RecordingHubMqttBridge();

        self::assertSame(['register_ack', 'event_ack'], $this->acks($mqtt, [$this->frame(0x01, 1), $this->undecryptable()]));
        self::assertCount(2, $mqtt->raw);
    }

    public function testAnEventThatDoesNotDecryptWaitsWhileItsBytesAreNotInRaw(): void
    {
        $mqtt = new class extends RecordingHubMqttBridge {
            public int $calls = 0;

            public function publishRaw(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
            {
                if (++$this->calls > 1) {
                    throw new \RuntimeException('broker unavailable');
                }
                parent::publishRaw($imei, $payload, $deviceType, $licenseId, $company);
            }
        };

        self::assertSame(['register_ack'], $this->acks($mqtt, [$this->frame(0x01, 1), $this->undecryptable()]));
    }

    private function undecryptable(): string
    {
        return (new PillDispenserAdapter())->encodeOutgoing(['packetType' => 0x03, 'serial' => 2, 'imei' => self::IMEI, 'tlv' => [], 'flag' => 0x04, 'appDataRaw' => str_repeat("\x5A", 32)]);
    }

    /** O histórico da dashboard é um extra: sem o Redis, o evento publicado confirma-se na mesma. */
    public function testAHistoryThatCannotBeWrittenDoesNotWithholdTheReply(): void
    {
        $redis = new InMemoryRedisClient();
        $redis->failOn = 'incr';

        self::assertSame(
            ['register_ack', 'change_ack'],
            $this->acks(new RecordingHubMqttBridge(), [$this->frame(0x01, 1), $this->frame(0x04, 2, [0x8135 => ['value' => "\x06"]])], new DeviceStore($redis)),
        );
    }

    /** A avaria que não saiu volta a sair quando o aparelho repete o pacote. */
    public function testAFaultThatWasNotPublishedIsPublishedOnTheResend(): void
    {
        $mqtt = new class extends RecordingHubMqttBridge {
            public int $failures = 1;

            public function publishEvent(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
            {
                if (($payload['type'] ?? '') === 'device_fault' && $this->failures-- > 0) {
                    throw new \RuntimeException('broker unavailable');
                }
                parent::publishEvent($imei, $payload, $deviceType, $licenseId, $company);
            }
        };
        $fault = [0x8121 => ['value' => "\x01"]];

        $acks = $this->acks($mqtt, [$this->frame(0x01, 1), $this->frame(0x02, 2, $fault), $this->frame(0x02, 2, $fault)]);

        self::assertSame(['register_ack', 'heartbeat_ack'], $acks);
        self::assertCount(1, array_filter($mqtt->events, static fn (array $event): bool => $event['type'] === 'device_fault'));
    }

    /** O alerta de bateria fraca que não saiu volta a sair na repetição, e o heartbeat espera por ele. */
    public function testALowBatteryAlertThatWasNotPublishedIsPublishedOnTheResend(): void
    {
        $mqtt = new class extends RecordingHubMqttBridge {
            public int $failures = 1;

            public function publishEvent(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
            {
                if (($payload['type'] ?? '') === 'low_battery' && $this->failures-- > 0) {
                    throw new \RuntimeException('broker unavailable');
                }
                parent::publishEvent($imei, $payload, $deviceType, $licenseId, $company);
            }
        };
        $low = [0x8104 => ['value' => "\x02"]];

        $acks = $this->acks($mqtt, [$this->frame(0x01, 1), $this->frame(0x02, 2, $low), $this->frame(0x02, 2, $low)]);

        self::assertSame(['register_ack', 'heartbeat_ack'], $acks);
        self::assertCount(1, array_filter($mqtt->events, static fn (array $event): bool => $event['type'] === 'low_battery'));
    }

    /** O arranque de uma actualização segue com a confirmação: retida uma, fica por mandar a outra. */
    public function testAnUpgradeStartWithheldWithTheReplyLeavesOnTheResend(): void
    {
        $mqtt = new class extends RecordingHubMqttBridge {
            public int $failures = 1;

            public function publishTelemetry(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
            {
                if (($payload['type'] ?? '') === 'battery' && $this->failures-- > 0) {
                    throw new \RuntimeException('broker unavailable');
                }
                parent::publishTelemetry($imei, $payload, $deviceType, $licenseId, $company);
            }
        };
        $path = (string)tempnam(sys_get_temp_dir(), 'fw');
        file_put_contents($path, str_repeat('f', 600));
        $upgrades = new InMemoryFirmwareUpgradeStore(['status' => 'requested', 'offset' => 0, 'path' => $path, 'size' => 600, 'checksum' => 1, 'timeout' => 1800]);
        $battery = [0x8103 => ['value' => "\x50"]];

        $acks = $this->acks($mqtt, [$this->frame(0x01, 1, $battery), $this->frame(0x01, 1, $battery)], upgrades: $upgrades);
        unlink($path);

        self::assertSame(['register_ack', 'upgrade_start'], $acks);
    }

    /** Sem o registo confirmado, o aparelho não está pronto para ordens: ficam na fila. */
    public function testQueuedCommandsWaitWhileTheRegisterIsNotAcknowledged(): void
    {
        $mqtt = new class extends RecordingHubMqttBridge {
            public function publishTelemetry(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
            {
                throw new \RuntimeException('broker unavailable');
            }
        };
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::IMEI, $this->frame(0x07, 9), ['id' => 'c-1'], 300);

        self::assertSame([], $this->acks($mqtt, [$this->frame(0x01, 1, [0x8002 => ['value' => "\x02\x05"]])], queue: $queue));
        self::assertCount(1, $queue->pendingFor(self::IMEI));
    }

    /**
     * @param list<string> $frames
     * @return list<string>
     */
    private function acks(RecordingHubMqttBridge $mqtt, array $frames, ?DeviceStore $store = null, ?RedisPendingDownlinkQueue $queue = null, ?InMemoryFirmwareUpgradeStore $upgrades = null): array
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
        $hub = new DeviceHubServer(
            IngressFixtures::whitelist([self::IMEI => IngressFixtures::device('Zayata', 'M228', 'pill_dispenser')]),
            $mqtt,
            downlinkQueue: $queue,
            deviceStore: $store,
            firmwareUpgrades: $upgrades,
        );
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
