<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\RedisPendingDownlinkQueue;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/**
 * Dois comandos só são o mesmo se mandarem fazer o mesmo.
 *
 * A fila junta o que se repete, para um aparelho que esteve muito tempo offline não receber
 * vinte vezes o mesmo pedido ao voltar. Mas a identidade era só o comando, e há comandos que
 * se distinguem apenas pelo valor: mandar a pulseira vibrar e mandá-la parar são o mesmo
 * `config:find_device`. O parar era engolido como repetição do começar, e a pulseira só se
 * calava quando o próprio firmware desistia.
 */
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
