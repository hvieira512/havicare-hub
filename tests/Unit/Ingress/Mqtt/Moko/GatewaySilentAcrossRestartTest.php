<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\MokoBridge;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Um gateway ligado quando o hub reinicia e que se cala dá-se como desligado: o Redis lembra-se dele, mesmo sem a ponte nova o ouvir. */
final class GatewaySilentAcrossRestartTest extends TestCase
{
    private const PREFIX = 'hub:dashboard';
    private const GATEWAY = 'd48c49f7909c';

    public function testAGatewaySilentSinceBeforeTheRestartIsAnnouncedDisconnected(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DeviceStore($redis);
        $store->deviceSeen(self::GATEWAY, [
            'supplier' => 'MOKO', 'model' => 'MKGW3', 'deviceType' => 'gateway', 'licenseId' => 1001,
            'company' => 'hitcare', 'protocol' => 'moko-mkgw3', 'transport' => 'mqtt', 'online' => '1',
        ]);
        $redis->zadd(self::PREFIX . ':online-devices-by-last-seen', [self::GATEWAY => time() - 181]);
        $mqtt = new RecordingHubMqttBridge();

        $this->bridge($mqtt, $store)->expireIdleGateways();

        self::assertSame(['device.disconnected'], array_column($mqtt->events, 'type'));
        self::assertSame(['offline'], array_column(array_column($mqtt->statuses, 'payload'), 'state'));
        self::assertSame('gateway', $mqtt->statuses[0]['deviceType']);
        self::assertFalse($store->device(self::GATEWAY)['online']);
    }

    public function testAGatewayThatStillTalksIsNotExpired(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DeviceStore($redis);
        $store->deviceSeen(self::GATEWAY, ['deviceType' => 'gateway', 'online' => '1']);
        $mqtt = new RecordingHubMqttBridge();

        $this->bridge($mqtt, $store)->expireIdleGateways();

        self::assertSame([], $mqtt->events);
    }

    private function bridge(RecordingHubMqttBridge $mqtt, DeviceStore $store): MokoBridge
    {
        return new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([self::GATEWAY => IngressFixtures::gateway('MKGW3')]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
            deviceStore: $store,
        );
    }
}
