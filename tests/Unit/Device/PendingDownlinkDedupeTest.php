<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Command\Downlink\RedisPendingDownlinkQueue;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** Vibrar e parar são ambos `config:find_device`: dois comandos só são o mesmo se o valor também o for. */
final class PendingDownlinkDedupeTest extends TestCase
{
    private const DEVICE = '9f69c4866e6c';

    public function testTheSameCommandWithADifferentValueIsNotADuplicate(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());

        $start = $queue->enqueue(self::DEVICE, 'config:find_device', [
            'command' => 'config:find_device',
            'payload' => ['enabled' => true],
        ], 300);
        $stop = $queue->enqueue(self::DEVICE, 'config:find_device', [
            'command' => 'config:find_device',
            'payload' => ['enabled' => false],
        ], 300);

        self::assertNotSame($start->dedupeKey, $stop->dedupeKey);
        self::assertCount(2, $queue->pendingFor(self::DEVICE));
    }

    /** Registar e pôr em linha saem pela ordem em que se pediram, mesmo no mesmo segundo. */
    public function testCommandsQueuedInTheSameSecondKeepTheirOrder(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $operations = ['registerScaleUser:u-1', 'registerScaleUser:u-2', 'deleteScaleUser:u-3', 'selectScaleUser:u-1'];
        foreach ($operations as $bytes) {
            $queue->enqueue(self::DEVICE, $bytes, null, 300);
        }

        self::assertSame($operations, array_map(static fn ($downlink): string => $downlink->bytes, $queue->pendingFor(self::DEVICE)));
    }

    /** Entregar um comando tira só esse da fila, e a de outro aparelho fica como estava. */
    public function testRemovingADownlinkLeavesTheOthers(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $first = $queue->enqueue(self::DEVICE, 'first', null, 300);
        $queue->enqueue(self::DEVICE, 'second', null, 300);
        $queue->enqueue('other-device', 'third', null, 300);

        $queue->remove($first);

        self::assertSame(['second'], array_map(static fn ($downlink): string => $downlink->bytes, $queue->pendingFor(self::DEVICE)));
        self::assertCount(1, $queue->pendingFor('other-device'));
    }

    /** O mesmo comando com o mesmo valor continua a ser um só: é para isso que a fila junta. */
    public function testTheSameCommandWithTheSameValueStaysOne(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());

        foreach ([true, true] as $enabled) {
            $queue->enqueue(self::DEVICE, 'config:find_device', [
                'command' => 'config:find_device',
                'payload' => ['enabled' => $enabled],
            ], 300);
        }

        self::assertCount(1, $queue->pendingFor(self::DEVICE));
    }
}
