<?php

declare(strict_types=1);

namespace Tests\Unit\State;

use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** Só os dispositivos com comandos por responder custam leituras, por trama e por varredura. */
final class PendingCommandsIndexTest extends TestCase
{
    private const WAITING = '868705080304962';
    private const IDLE = '868705080304963';

    private InMemoryRedisClient $redis;
    private DeviceStore $store;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedisClient();
        $this->store = new DeviceStore($this->redis);
        foreach ([self::WAITING, self::IDLE] as $imei) {
            $this->store->runtime->registerDevice($imei, 'Wonlex', 'HW20PRO');
        }
        $this->store->recordCommand(self::WAITING, 'one', $this->command('waiting'));
        $this->store->recordCommand(self::IDLE, 'two', $this->command('acked'));
        // A primeira leitura semeia o índice a partir de tudo o que está guardado, uma vez só.
        $this->store->expireWaitingCommands(3600);
    }

    public function testAFrameFromADeviceWithNothingPendingReadsNoCommands(): void
    {
        $this->redis->calls = [];

        $this->store->markCommandReply(self::IDLE, 'upHeartRate', 1, 'w:update');

        self::assertSame([], $this->readsOfCommandsOf(self::IDLE));
    }

    public function testTheSweepsSkipDevicesWithNothingPending(): void
    {
        $this->redis->calls = [];

        $this->store->retryWaitingCommands(60, 3600, 3, static fn (): string => 'sent');
        $this->store->expireWaitingCommands(3600);

        self::assertSame([], $this->readsOfCommandsOf(self::IDLE));
        self::assertNotSame([], $this->readsOfCommandsOf(self::WAITING));
    }

    public function testAnAnsweredCommandLeavesTheIndex(): void
    {
        $this->store->markCommandReply(self::WAITING, 'upHeartRate', 1, 'w:update');
        $this->store->retryWaitingCommands(60, 3600, 3, static fn (): string => 'sent');
        $this->redis->calls = [];

        $this->store->markCommandReply(self::WAITING, 'upHeartRate', 1, 'w:update');

        self::assertSame([], $this->readsOfCommandsOf(self::WAITING));
    }

    /** Comandos gravados antes de o índice existir continuam a ser repetidos. */
    public function testCommandsRecordedBeforeTheIndexExistedAreStillRetried(): void
    {
        $this->redis->del(['hub:dashboard:devices-with-pending-commands', 'hub:dashboard:pending-commands-indexed']);
        $dispatched = [];

        (new DeviceStore($this->redis))->retryWaitingCommands(
            60,
            3600,
            3,
            static function (string $imei) use (&$dispatched): string {
                $dispatched[] = $imei;
                return 'sent';
            },
        );

        self::assertSame([self::WAITING], $dispatched);
    }

    /** @return array<string, mixed> */
    private function command(string $status): array
    {
        return [
            'status' => $status,
            'protocol' => 'wonlex-json',
            'nativeType' => 'dnHeartRate',
            'expectedReplyTypes' => ['upHeartRate'],
            'retryable' => true,
            'bytes' => '{"type":"dnHeartRate"}',
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 120),
        ];
    }

    /** @return list<array{string, mixed}> */
    private function readsOfCommandsOf(string $imei): array
    {
        return array_values(array_filter(
            $this->redis->calls,
            static fn (array $call): bool => $call[0] === 'lrange' && $call[1] === "hub:dashboard:device:{$imei}:commands",
        ));
    }
}
