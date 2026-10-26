<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\RelayPublisher;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Um aparelho que só fala através de um gateway liga-se quando um gateway o volta a ouvir. */
final class RelayedConnectionTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const SENSOR = 'eec5000202f9';

    public function testARelayedDeviceHeardAgainIsAnnouncedConnectedOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $store = new DeviceStore(new InMemoryRedisClient());
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
        self::assertSame('info', $mqtt->events[0]['payload']['severity']);
    }

    /** Ouvido por vários gateways, o que conta para o desligado é o último. */
    public function testTheLastGatewayToHearARelayedDeviceIsKept(): void
    {
        $store = new DeviceStore(new InMemoryRedisClient());
        $relay = new RelayPublisher(
            new RecordingHubMqttBridge(),
            $store,
            new ArrayObservationStateStore(),
            IngressFixtures::whitelist([self::SENSOR => IngressFixtures::diaperSensor()]),
            null,
            0,
            0,
        );
        $sensor = ['imei' => self::SENSOR] + IngressFixtures::diaperSensor();

        $relay->publishTelemetry($sensor, ['imei' => self::GATEWAY] + IngressFixtures::gateway('MKGW3'), 'monit-mecs-pro-ble', ['telemetry' => []], -70);
        $relay->publishTelemetry($sensor, ['imei' => 'c5e390f30bce'] + IngressFixtures::gateway('MKGW3'), 'monit-mecs-pro-ble', ['telemetry' => []], -80);

        self::assertSame('c5e390f30bce', $store->runtime->device(self::SENSOR)['gatewayId'] ?? null);
    }
}
