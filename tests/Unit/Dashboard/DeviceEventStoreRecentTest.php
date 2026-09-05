<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\DeviceEventStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/**
 * Com um cursor, `recent()` devolve só o que entrou depois — e pára na primeira entrada
 * antiga, porque a lista é monótona.
 */
final class DeviceEventStoreRecentTest extends TestCase
{
    private function store(): DeviceEventStore
    {
        return new DeviceEventStore(new InMemoryRedisClient(), limit: 100, prefix: 'test:events');
    }

    public function testReturnsOnlyEntriesNewerThanTheCursor(): void
    {
        $store = $this->store();
        foreach (['a', 'b', 'c'] as $type) {
            $store->append('imei1', 'telemetry', ['type' => $type]);
        }

        // A mais nova à cabeça; com cursor no seq 1, saem o 3 e o 2.
        self::assertSame([3, 2], array_map(
            static fn (array $e): int => (int)$e['seq'],
            $store->recent('imei1', 'telemetry', 1),
        ));
    }

    public function testWithoutCursorReturnsAll(): void
    {
        $store = $this->store();
        foreach (['a', 'b', 'c'] as $type) {
            $store->append('imei1', 'telemetry', ['type' => $type]);
        }

        self::assertSame([3, 2, 1], array_map(
            static fn (array $e): int => (int)$e['seq'],
            $store->recent('imei1', 'telemetry', 0),
        ));
    }
}
