<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** O histórico das ligações vive à parte dos eventos, e só guarda mudanças de estado. */
final class ConnectionHistoryTest extends TestCase
{
    private const IMEI = '594B3CB31A87';
    private const PREFIX = 'test:dashboard';

    private InMemoryRedisClient $redis;
    private DeviceStore $store;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedisClient();
        $this->store = new DeviceStore($this->redis, prefix: self::PREFIX);
    }

    /** Os alarmes de um radar enchem os cem eventos em horas, e levariam as ligações com eles. */
    public function testAConnectionEventGoesToTheConnectionHistoryAndNotToTheEvents(): void
    {
        $this->store->append(self::IMEI, 'events', ['type' => 'device.disconnected']);
        $this->store->append(self::IMEI, 'events', ['type' => 'apnea']);

        self::assertSame(['device.disconnected'], $this->types('connections'));
        self::assertSame(['apnea'], $this->types('events'));
    }

    public function testARepeatedConnectionStateIsKeptOnce(): void
    {
        $this->store->append(self::IMEI, 'events', ['type' => 'device.connected']);
        $this->store->append(self::IMEI, 'events', ['type' => 'device.connected']);
        $this->store->append(self::IMEI, 'events', ['type' => 'device.disconnected']);

        self::assertSame(['device.disconnected', 'device.connected'], $this->types('connections'));
    }

    /** O estado anterior vem do Redis e não da memória: um reinício do hub não é uma reconexão. */
    public function testDeviceSeenAndOfflineSayWhenTheStateChanged(): void
    {
        self::assertTrue($this->store->deviceSeen(self::IMEI, ['online' => '1']));
        self::assertFalse($this->store->deviceSeen(self::IMEI, ['online' => '1']));

        $restarted = new DeviceStore($this->redis, prefix: self::PREFIX);
        self::assertFalse($restarted->deviceSeen(self::IMEI, ['online' => '1']));

        self::assertTrue($restarted->deviceOffline(self::IMEI));
        self::assertFalse($restarted->deviceOffline(self::IMEI));
        self::assertTrue($restarted->deviceSeen(self::IMEI, ['online' => '1']));
    }

    /** Quem já estava ligado antes de haver histórico começa-o na primeira vez que é visto. */
    public function testAnOnlineDeviceWithoutConnectionHistoryStartsIt(): void
    {
        $this->store->deviceSeen(self::IMEI, ['online' => '1']);
        self::assertSame([], $this->types('connections'));

        $restarted = new DeviceStore($this->redis, prefix: self::PREFIX);
        $restarted->deviceSeen(self::IMEI, ['online' => '1']);
        $restarted->deviceSeen(self::IMEI, ['online' => '1']);

        self::assertSame(['device.connected'], $this->types('connections'));
    }

    public function testStaleDevicesOfOneTypeExpireAndAreNamed(): void
    {
        $this->store->deviceSeen(self::IMEI, ['online' => '1', 'deviceType' => 'radar']);
        $this->store->deviceSeen('861265061009822', ['online' => '1', 'deviceType' => 'watch']);
        $this->rewindLastSeen(self::IMEI, 181);
        $this->rewindLastSeen('861265061009822', 181);

        self::assertSame([self::IMEI], $this->store->expireStaleDevices(180, 'radar'));
        self::assertSame([], $this->store->expireStaleDevices(180, 'radar'));
        self::assertFalse($this->store->device(self::IMEI)['online']);
        self::assertTrue($this->store->device('861265061009822')['online']);
    }

    /** @return list<string> */
    private function types(string $list): array
    {
        return array_column($this->store->recent(self::IMEI, $list), 'type');
    }

    private function rewindLastSeen(string $imei, int $seconds): void
    {
        $this->redis->zadd(self::PREFIX . ':online-devices-by-last-seen', [$imei => time() - $seconds]);
    }
}
