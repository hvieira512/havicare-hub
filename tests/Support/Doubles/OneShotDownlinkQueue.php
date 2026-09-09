<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Device\PendingDownlink;
use Hub\Device\PendingDownlinkQueue;

/**
 * Uma fila com um comando lá dentro, para exercitar a entrega sem Redis.
 *
 * O que interessa nos testes de entrega é o que a ponte faz com o que estava em fila, e não
 * como a fila o guardou.
 */
final class OneShotDownlinkQueue implements PendingDownlinkQueue
{
    /** @var list<PendingDownlink> */
    public array $removed = [];

    /** @param array<string, mixed>|null $command */
    public function __construct(
        private readonly string $bytes,
        private readonly ?array $command = null,
        private readonly string $dedupeKey = 'test-dedupe',
    ) {
    }

    public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink
    {
        return new PendingDownlink($imei, $this->dedupeKey, $bytes, $command, 0, $ttlSeconds);
    }

    /** @return list<PendingDownlink> */
    public function pendingFor(string $imei): array
    {
        return [new PendingDownlink($imei, $this->dedupeKey, $this->bytes, $this->command, 0, 0)];
    }

    public function remove(PendingDownlink $downlink): void
    {
        $this->removed[] = $downlink;
    }
}
