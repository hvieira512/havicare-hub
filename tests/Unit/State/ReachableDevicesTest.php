<?php

declare(strict_types=1);

namespace Tests\Unit\State;

use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** Alcançável é ligado e acordado: um aparelho a dormir não recebe ordens agora. */
final class ReachableDevicesTest extends TestCase
{
    public function testOnlyOnlineAndAwakeDevicesAreReachable(): void
    {
        $store = new DeviceStore(new InMemoryRedisClient());
        foreach (['awake', 'asleep', 'offline'] as $imei) {
            $store->deviceSeen($imei, ['online' => '1', 'deviceType' => 'watch']);
        }
        $store->runtime->deviceAsleep('asleep', true);
        $store->runtime->deviceOffline('offline');

        self::assertSame(['awake'], $store->runtime->reachableDeviceImeis());
    }
}
