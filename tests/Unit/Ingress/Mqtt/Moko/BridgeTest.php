<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Device\HubMqttBridge;
use Hub\Ingress\Mqtt\Moko\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\FakeMqttSubscriber;

final class BridgeTest extends TestCase
{
    private const ADV_DATA = '0201041aff5900021535c80410418015dc8200410418415dc8200202f9c3';
    private const MKGW4_HEARTBEAT = 'ef3004c5e390f30bce00400000046a759cd8010007464444204c54450200011203000210740400060002000200000500010006000f38363130373630383232333235313107000400000998';

    public function testAuthorizedLinkedSensorPublishesIndependentTelemetryAndDeduplicatesReplay(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt, true);
        $payload = $this->scanPayload();

        $bridge->handleReceivedMessage('havicare-hub/null/0/gw/d48c49f7909c/raw', $payload);
        $bridge->handleReceivedMessage('havicare-hub/null/0/gw/d48c49f7909c/raw', $payload);

        self::assertCount(2, $mqtt->raw);
        // A proximidade é reportada uma vez por avistamento aceite, à frente da telemetria
        // normalizada; a mensagem repetida é deduplicada antes dela.
        self::assertSame(['proximity', 'battery', 'diaper_moisture', 'diaper_moisture_level', 'diaper_condition'], array_column($mqtt->telemetry, 'type'));
        self::assertSame(['device.connected'], array_column($mqtt->events, 'type'));
        self::assertSame('hitcare', $mqtt->telemetry[0]['company']);
        self::assertSame(1001, $mqtt->telemetry[0]['licenseId']);
        self::assertSame('diaper_sensor', $mqtt->telemetry[0]['deviceType']);
    }

    public function testHeartbeatPublishesGatewayConnectivityAndLifecycleOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt, true);
        $payload = json_encode([
            'msg_id' => 3004,
            'device_info' => ['mac' => 'd48c49f7909c'],
            'data' => ['timestamp' => 0, 'net_interface' => 1, 'wifi_rssi' => -54],
        ], JSON_THROW_ON_ERROR);

        $bridge->handleReceivedMessage('havicare-hub/null/0/gw/d48c49f7909c/raw', $payload);
        $bridge->handleReceivedMessage('havicare-hub/null/0/gw/d48c49f7909c/raw', $payload);

        self::assertCount(2, $mqtt->raw);
        self::assertSame(['connectivity'], array_column($mqtt->telemetry, 'type'));
        self::assertSame(
            ['interface' => 'wifi', 'signalStrengthDbm' => -54],
            $mqtt->telemetry[0]['payload']['data'] ?? null
        );
        self::assertSame('gateway', $mqtt->telemetry[0]['deviceType']);
        self::assertSame(['device.connected'], array_column($mqtt->events, 'type'));
        self::assertSame(['online'], array_column(array_column($mqtt->statuses, 'payload'), 'state'));
        self::assertTrue($mqtt->statuses[0]['retain'] ?? false);
    }

    public function testMkgw4HeartbeatPublishesCellularConnectivityBatteryAndOnlineStatus(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt, true);
        $bridge->handleReceivedMessage(
            'havicare-hub/null/0/gw/c5e390f30bce/raw',
            hex2bin(self::MKGW4_HEARTBEAT)
        );

        self::assertSame(['connectivity', 'battery'], array_column($mqtt->telemetry, 'type'));
        self::assertSame('cellular', $mqtt->telemetry[0]['payload']['data']['interface'] ?? null);
        self::assertSame(4212, $mqtt->telemetry[1]['payload']['data']['voltageMv'] ?? null);
        self::assertSame('moko-mkgw4', $mqtt->raw[0]['payload']['debug']['protocol'] ?? null);
        self::assertSame(['online'], array_column(array_column($mqtt->statuses, 'payload'), 'state'));
    }

    public function testUnlinkedSensorDoesNotPublishSensorTelemetry(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt, false)->handleReceivedMessage(
            'havicare-hub/null/0/gw/d48c49f7909c/raw',
            $this->scanPayload()
        );
        self::assertSame([], $mqtt->telemetry);
    }

    public function testPayloadGatewayMacMustMatchTopic(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $payload = json_decode($this->scanPayload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['device_info']['mac'] = 'c5e390f30bce';
        $this->bridge($mqtt, true)->handleReceivedMessage(
            'havicare-hub/null/0/gw/d48c49f7909c/raw',
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertSame([], $mqtt->raw);
        self::assertSame([], $mqtt->telemetry);
    }

    public function testGatewayPublishesDisconnectedAfterIdleTimeout(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $now = 1000.0;
        $bridge = $this->bridge($mqtt, true, static function () use (&$now): float {
            return $now;
        }, 1);
        $bridge->handleReceivedMessage('havicare-hub/null/0/gw/d48c49f7909c/raw', $this->scanPayload());
        $now = 1002.0;
        $bridge->expireIdleGateways();

        self::assertSame(['device.connected', 'device.disconnected'], array_column($mqtt->events, 'type'));
        self::assertSame(['online', 'offline'], array_column(array_column($mqtt->statuses, 'payload'), 'state'));
    }

    private function bridge(RecordingHubMqttBridge $mqtt, bool $linked, ?callable $clock = null, int $idleTimeout = 180): Bridge
    {
        $whitelist = IngressFixtures::whitelist([
            'd48c49f7909c' => IngressFixtures::gateway('MKGW3'),
            'c5e390f30bce' => IngressFixtures::gateway('MKGW4'),
            'eec5000202f9' => IngressFixtures::diaperSensor(),
        ]);
        return new Bridge(
            new FakeMqttSubscriber(),
            $whitelist,
            $mqtt,
            IngressFixtures::links($linked),
            new ArrayObservationStateStore(),
            gatewayIdleTimeoutSeconds: $idleTimeout,
            clock: $clock,
        );
    }

    private function scanPayload(): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => 'd48c49f7909c'],
            'data' => [[
                'adv_data' => self::ADV_DATA, 'rsp_data' => '0f094d4f4e4954204d4543532050524f',
                'type_code' => 10, 'type' => 'other', 'rssi' => -83,
                'connectable' => 0, 'mac' => 'eec5000202f9',
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
