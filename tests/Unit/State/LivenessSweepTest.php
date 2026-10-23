<?php

declare(strict_types=1);

namespace Tests\Unit\State;

use Hub\State\DeviceStore;
use Hub\State\RedisKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** Um aparelho dá-se por desligado ao fim de dois sinais de vida falhados do protocolo dele, mais 30 s. */
final class LivenessSweepTest extends TestCase
{
    private const DEVICE = 'aabbccddeeff';
    private const GATEWAY = '112233445566';

    private InMemoryRedisClient $redis;

    private DeviceStore $store;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedisClient();
        $this->store = new DeviceStore($this->redis);
    }

    /** @return iterable<string, array{string, int}> */
    public static function intervals(): iterable
    {
        yield 'wonlex without a socket' => ['wonlex-json', 300];
        yield 'four p touch without a socket' => ['four-p-touch', 300];
        yield 'vivistar without a socket' => ['vivistar-iw', 480];
        yield 'pill dispenser without a socket' => ['zayata-m228', 60];
        yield 'radar' => ['qinglanst-radar', 60];
        yield 'moko mkgw3 gateway' => ['moko-mkgw3', 30];
        yield 'moko mkgw4 gateway' => ['moko-mkgw4', 600];
        yield 'havicare ble gateway' => ['havicare-ble-gateway', 60];
        yield 'monit diaper sensor' => ['monit-mecs-pro-ble', 600];
        yield 'moko w6 bracelet' => ['moko-w6', 600];
        yield 'moko w6b bracelet' => ['moko-w6b', 600];
        yield 'veepoo bracelet' => ['veepoo-ble', 30];
        yield 'ncs' => ['voerka-ncs', 1200];
        yield 'tuya sos button' => ['tuya-cloud', 28800];
    }

    #[DataProvider('intervals')]
    public function testADeviceSilentForTwoIntervalsAndTheGraceGoesOffline(string $protocol, int $interval): void
    {
        $this->seen(self::DEVICE, $protocol, 2 * $interval + 30);

        self::assertSame([self::DEVICE], $this->store->runtime->expireStaleDevices());
        self::assertFalse($this->store->runtime->device(self::DEVICE)['online']);
    }

    #[DataProvider('intervals')]
    public function testADeviceSilentForLessStaysOnline(string $protocol, int $interval): void
    {
        $this->seen(self::DEVICE, $protocol, 2 * $interval + 28);

        self::assertSame([], $this->store->runtime->expireStaleDevices());
        self::assertTrue($this->store->runtime->device(self::DEVICE)['online']);
    }

    /** Adormecido continua alcançável: o prazo conta do último despertar, durma ou não. */
    public function testASleepingTuyaButtonGoesOfflineOnlyAfterTwoMissedWakeUps(): void
    {
        $this->seen(self::DEVICE, 'tuya-cloud', 28800 + 60);
        $this->store->deviceAsleep(self::DEVICE, true);
        self::assertSame([], $this->store->runtime->expireStaleDevices());

        $this->seen(self::DEVICE, 'tuya-cloud', 2 * 28800 + 30);
        $this->store->deviceAsleep(self::DEVICE, true);
        self::assertSame([self::DEVICE], $this->store->runtime->expireStaleDevices());
    }

    public function testOnlyAnOnlineDeviceFallsAsleep(): void
    {
        self::assertFalse($this->store->deviceAsleep(self::DEVICE, true));

        $this->seen(self::DEVICE, 'tuya-cloud', 10);
        self::assertTrue($this->store->deviceAsleep(self::DEVICE, true));
        self::assertFalse($this->store->deviceAsleep(self::DEVICE, true));
        self::assertTrue($this->store->deviceAsleep(self::DEVICE, false));
    }

    /** @return iterable<string, array{string}> */
    public static function onDemandDevices(): iterable
    {
        yield 'lefu scale' => ['lefu-torre'];
        yield 'joytech blood pressure monitor' => ['joytech-bp'];
        yield 'joytech ecg blood pressure monitor' => ['joytech-bp-ecg'];
        yield 'joytech thermometer' => ['joytech-et'];
        yield 'joytech oximeter' => ['joytech-spo2'];
    }

    /** Liga-se para medir e desliga-se: a sessão que se cala 120 s acabou, mesmo sem o `connected: false`. */
    #[DataProvider('onDemandDevices')]
    public function testAnOnDemandSessionSilentForTheSessionSilenceEnds(string $protocol): void
    {
        $this->seen(self::DEVICE, $protocol, 120);

        self::assertSame([self::DEVICE], $this->store->runtime->expireStaleDevices());
    }

    #[DataProvider('onDemandDevices')]
    public function testAnOnDemandSessionThatStillTalksGoesOn(string $protocol): void
    {
        $this->seen(self::DEVICE, $protocol, 100);

        self::assertSame([], $this->store->runtime->expireStaleDevices());
    }

    /** Uma trama no meio da sessão prova-a viva, sem a voltar a abrir. */
    public function testHearingAnOnlineDeviceRefreshesItsSession(): void
    {
        $this->seen(self::DEVICE, 'joytech-et', 119);

        $this->store->deviceHeard(self::DEVICE);

        self::assertSame([], $this->store->runtime->expireStaleDevices());
    }

    public function testHearingAnOfflineDeviceDoesNotBringItBack(): void
    {
        $this->seen(self::DEVICE, 'joytech-et', 10);
        $this->store->deviceOffline(self::DEVICE);

        $this->store->deviceHeard(self::DEVICE);

        self::assertFalse($this->store->runtime->device(self::DEVICE)['online']);
        self::assertSame([], $this->store->runtime->onlineDeviceImeis());
    }

    public function testOnlyTheRequestedTypeIsExpired(): void
    {
        $this->seen(self::DEVICE, 'qinglanst-radar', 3600, deviceType: 'radar');
        $this->seen(self::GATEWAY, 'moko-mkgw3', 3600, deviceType: 'gateway');

        self::assertSame([self::DEVICE], $this->store->runtime->expireStaleDevices('radar'));
        self::assertTrue($this->store->runtime->device(self::GATEWAY)['online']);
    }

    private function seen(string $key, string $protocol, int $silentSeconds, string $deviceType = 'watch'): void
    {
        $this->store->deviceSeen($key, ['online' => '1', 'protocol' => $protocol, 'deviceType' => $deviceType]);
        $this->redis->zadd(RedisKeys::onlineDevices(), [$key => time() - $silentSeconds]);
    }
}
