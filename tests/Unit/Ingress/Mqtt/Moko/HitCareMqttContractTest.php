<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Device\RawPayload;
use Hub\Ingress\Mqtt\Moko\MokoBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O que o `havicare-hub-client` lê das fraldas e dos gateways, que estão em clientes. */
final class HitCareMqttContractTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const SENSOR = 'eec5000202f9';

    /** Guarda o hitCare: os tipos, os campos do `data` e o envelope da fralda que o hub-client lê. */
    public function testTheDiaperMessagesHaveTheShapeHitCareParses(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $topic = 'havicare-hub/null/0/gw/' . self::GATEWAY . '/raw';

        $bridge->handleReceivedMessage($topic, IngressFixtures::monitScan(self::GATEWAY, self::SENSOR, 'clean'));
        $bridge->handleReceivedMessage($topic, IngressFixtures::monitScan(self::GATEWAY, self::SENSOR, 'change_required', 79));

        $telemetry = array_column(array_column($mqtt->telemetry, 'payload'), null, 'type');
        self::assertIsInt($telemetry['battery']['data']['percent'] ?? null);
        self::assertIsNumeric($telemetry['diaper_moisture']['data']['maximumDelta'] ?? null);
        self::assertIsInt($telemetry['diaper_moisture']['data']['affectedChannelCount'] ?? null);
        self::assertIsInt($telemetry['diaper_moisture_level']['data']['index'] ?? null);
        self::assertIsInt($telemetry['diaper_moisture_level']['data']['alertIndex'] ?? null);
        self::assertSame('change_required', $telemetry['diaper_condition']['data']['state'] ?? null);

        $envelope = $telemetry['diaper_condition'];
        self::assertSame(self::SENSOR, $envelope['device']['id'] ?? null);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)($envelope['occurredAt'] ?? ''));
        self::assertSame(self::GATEWAY, $envelope['source']['gatewayId'] ?? null);
        self::assertIsInt($envelope['source']['rssiDbm'] ?? null);
        self::assertNotContains('heartbeat', array_keys($telemetry));

        $events = array_column(array_column($mqtt->events, 'payload'), null, 'type');
        self::assertSame('clean', $events['change_required']['data']['previousState'] ?? null);
        self::assertSame(self::GATEWAY, $events['device.connected']['device']['id'] ?? null);
        self::assertContains('online', array_column(array_column($mqtt->statuses, 'payload'), 'state'));
    }

    /** Guarda o hitCare: o gateway que se cala sai `offline`, com o `updatedAt`, e o evento `device.disconnected`. */
    public function testTheGatewayGoingSilentHasTheShapeHitCareParses(): void
    {
        $status = RawPayload::status(self::GATEWAY, 'MOKO', 'MKGW3', 'offline');
        $event = RawPayload::event(self::GATEWAY, 'MOKO', 'MKGW3', 'device.disconnected');

        self::assertSame('offline', $status['state']);
        self::assertMatchesRegularExpression('/Z$/', $status['updatedAt']);
        self::assertSame('device.disconnected', $event['type']);
        self::assertSame(self::GATEWAY, $event['device']['id']);
    }

    private function bridge(RecordingHubMqttBridge $mqtt): MokoBridge
    {
        return new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::SENSOR => IngressFixtures::diaperSensor(),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
        );
    }
}
