<?php

declare(strict_types=1);

namespace Tests\Unit\State;

use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** O stream relê o histórico a cada push: uma lista sem nada depois do cursor não se lê. */
final class RecentSinceCursorTest extends TestCase
{
    public function testAListWithNothingPastTheCursorIsNotRead(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DeviceStore($redis);
        $store->append('868705080304962', 'telemetry', ['type' => 'heart_rate', 'data' => ['bpm' => 70]]);
        $cursor = $store->events->latestSequence('868705080304962', 'telemetry');
        $redis->calls = [];

        self::assertSame([], $store->events->recent('868705080304962', 'telemetry', $cursor));
        self::assertNotContains('lrange', array_column($redis->calls, 0));
    }

    public function testEntriesPastTheCursorStillArrive(): void
    {
        $store = new DeviceStore(new InMemoryRedisClient());
        $store->append('868705080304962', 'telemetry', ['type' => 'heart_rate', 'data' => ['bpm' => 70]]);
        $cursor = $store->events->latestSequence('868705080304962', 'telemetry');
        $store->append('868705080304962', 'telemetry', ['type' => 'heart_rate', 'data' => ['bpm' => 72]]);

        self::assertSame([72], array_map(
            static fn (array $entry): int => $entry['data']['bpm'],
            $store->events->recent('868705080304962', 'telemetry', $cursor),
        ));
    }
}
