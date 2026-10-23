<?php

declare(strict_types=1);

namespace Tests\Unit\State;

use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** Um IMEI apagado e registado de novo começa sem nada do que o anterior deixou. */
final class DeviceDeletionTest extends TestCase
{
    private const IMEI = '861265061009822';

    public function testARegisteredAgainDeviceStartsEmpty(): void
    {
        $store = new DeviceStore(new InMemoryRedisClient());
        $store->runtime->registerDevice(self::IMEI, 'Vivistar', 'L08 Pro');
        $store->deviceSeen(self::IMEI, ['online' => '1']);
        foreach (['raw', 'telemetry', 'events'] as $list) {
            $store->append(self::IMEI, $list, ['type' => 'heart_rate', 'data' => ['bpm' => 70]]);
        }
        $store->append(self::IMEI, 'events', ['type' => 'device.connected']);

        $store->deleteDevice(self::IMEI);
        $store->runtime->registerDevice(self::IMEI, 'Vivistar', 'L08 Pro');

        foreach (['raw', 'telemetry', 'events', 'connections'] as $list) {
            self::assertSame([], $store->events->latestOfEachType(self::IMEI, $list), "o último de {$list} ficou");
            self::assertSame(0, $store->events->latestSequence(self::IMEI, $list), "o número de ordem de {$list} ficou");
        }
    }
}
