<?php

declare(strict_types=1);

namespace Tests\Unit\State;

use Hub\State\ActiveAlarms;
use Hub\State\RedisKeys;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

final class ActiveAlarmsTest extends TestCase
{
    public function testAnotherProcessSeesWhatWasAnnounced(): void
    {
        $redis = new InMemoryRedisClient();
        (new ActiveAlarms($redis))->set('a', 'low_battery', '1', 60);

        self::assertSame('1', (new ActiveAlarms($redis))->get('a', 'low_battery'));
    }

    /** Um aparelho calado para lá do prazo deixa de calar o alarme que volte. */
    public function testAnEntryPastItsTtlIsGone(): void
    {
        $redis = new InMemoryRedisClient();
        $redis->hset(RedisKeys::activeAlarms('a'), 'low_battery', (time() - 1) . ':1');

        self::assertNull((new ActiveAlarms($redis))->get('a', 'low_battery'));
    }

    public function testNullClearsTheEntry(): void
    {
        $alarms = new ActiveAlarms(new InMemoryRedisClient());
        $alarms->set('a', 'low_battery', '1', 60);
        $alarms->set('a', 'low_battery', null, 60);

        self::assertNull($alarms->get('a', 'low_battery'));
    }
}
