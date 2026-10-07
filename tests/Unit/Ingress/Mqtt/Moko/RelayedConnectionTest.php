<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\RelayPublisher;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Um aparelho que só fala através de um gateway liga-se quando um gateway o volta a ouvir. */
final class RelayedConnectionTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const SENSOR = 'eec5000202f9';

    public function testARelayedDeviceHeardAgainIsAnnouncedConnectedOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $store = new DeviceStore(new InMemoryRedisClient(), prefix: 'test:dashboard:relay');
        $relay = new RelayPublisher(
            $mqtt,
            $store,
            new ArrayObservationStateStore(),
            IngressFixtures::whitelist([self::SENSOR => IngressFixtures::diaperSensor()]),
            null,
            0,
            0,
        );
        $sensor = ['imei' => self::SENSOR] + IngressFixtures::diaperSensor();
        $gateway = ['imei' => self::GATEWAY] + IngressFixtures::gateway('MKGW3');

        $relay->publishTelemetry($sensor, $gateway, 'monit-mecs-pro-ble', ['telemetry' => []], -70);
        $relay->publishTelemetry($sensor, $gateway, 'monit-mecs-pro-ble', ['telemetry' => []], -71);

        self::assertSame(['device.connected'], array_column($mqtt->events, 'type'));
        self::assertSame(['device.connected'], array_column($store->recent(self::SENSOR, 'connections'), 'type'));
    }
}
