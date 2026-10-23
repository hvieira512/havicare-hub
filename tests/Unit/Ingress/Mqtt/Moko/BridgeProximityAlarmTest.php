<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\MokoBridge;
use Hub\Ingress\Mqtt\Moko\MokoGatewayOptions;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\FixedProximityAlarm;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O `proximity_alarm` que o hub decide quando um dispositivo ligado chega perto de um gateway com o alarme ativo. */
final class BridgeProximityAlarmTest extends TestCase
{
    private const GATEWAY = 'c5e390f30bce';
    private const OTHER_GATEWAY = 'd48c49f7909c';
    private const BRACELET = 'fbd87c59ba8b';
    private const SENSOR = 'eec5000202f9';

    private float $now = 1000.0;
    private RecordingHubMqttBridge $mqtt;
    private DeviceStore $store;
    private FixedProximityAlarm $alarm;
    private MokoBridge $bridge;

    protected function setUp(): void
    {
        $this->mqtt = new RecordingHubMqttBridge();
        $this->store = new DeviceStore(new InMemoryRedisClient());
        $this->alarm = new FixedProximityAlarm();
        $this->bridge = new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW4'),
                self::OTHER_GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::BRACELET => IngressFixtures::bracelet('W6B'),
                self::SENSOR => IngressFixtures::diaperSensor(),
            ]),
            $this->mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
            deviceStore: $this->store,
            options: new MokoGatewayOptions(proximityAlarm: $this->alarm),
            clock: fn(): float => $this->now,
        );
    }

    public function testAGatewayWithTheAlarmOffRaisesNothing(): void
    {
        $this->walkPast([-70, -60, -50, -48]);

        self::assertSame([], $this->alarms());
    }

    public function testAnActiveGatewayRaisesTheAlarmOnTheDevicesEvents(): void
    {
        $this->alarm->byGateway[self::GATEWAY] = ['mode' => 'active'];

        $this->walkPast([-70, -64, -49]);

        $alarms = $this->alarms();
        self::assertCount(1, $alarms);
        self::assertSame(self::BRACELET, $alarms[0]['imei'], 'sai no canal do dispositivo, não no do gateway');
        self::assertSame('bracelet', $alarms[0]['deviceType']);
        $payload = $alarms[0]['payload'];
        self::assertSame([
            'gatewayId' => self::GATEWAY,
            'rssiDbm' => -49,
            'window' => ['maxDbm' => -49, 'medianDbm' => -64, 'minDbm' => -70, 'samples' => 3],
        ], $payload['data']);
        self::assertSame(['protocol' => 'moko-w6b', 'gatewayId' => self::GATEWAY], $payload['source']);
        self::assertSame(self::BRACELET, $payload['device']['id']);
    }

    public function testBelowTheThresholdNothingFires(): void
    {
        $this->alarm->byGateway[self::GATEWAY] = ['mode' => 'active'];

        $this->walkPast([-75, -70, -66, -62]);

        self::assertSame([], $this->alarms());
    }

    /** Uma pessoa parada à porta não dá um alarme por segundo. */
    public function testLingeringNearTheGatewayRaisesOnlyOneAlarmPerCooldown(): void
    {
        $this->alarm->byGateway[self::GATEWAY] = ['mode' => 'active', 'cooldownSeconds' => 60];

        $this->walkPast([-50, -50, -50, -50, -50, -50]);
        self::assertCount(1, $this->alarms());

        $this->now += 61.0;
        $this->walkPast([-50, -50, -50]);
        self::assertCount(2, $this->alarms());
    }

    public function testOnlyTheChosenDevicesRaiseIt(): void
    {
        $this->alarm->byGateway[self::GATEWAY] = ['mode' => 'active', 'devices' => ['a0b1c2d3e4f5']];

        $this->walkPast([-50, -50, -50]);

        self::assertSame([], $this->alarms());
    }

    public function testTheAlarmBelongsToTheGatewayThatHeardTheDevice(): void
    {
        $this->alarm->byGateway[self::OTHER_GATEWAY] = ['mode' => 'active'];

        $this->walkPast([-50, -50, -50]);

        self::assertSame([], $this->alarms());
    }

    /** A calibrar avalia-se igual, mas fica só a marca no avistamento, para a dashboard. */
    public function testCalibratingMarksTheCandidateWithoutRaisingTheAlarm(): void
    {
        $this->alarm->byGateway[self::GATEWAY] = ['mode' => 'calibrating'];

        $this->walkPast([-50, -50, -50]);

        self::assertSame([], $this->alarms());
        self::assertArrayHasKey(self::GATEWAY, $this->store->proximityAlarmCandidates(self::BRACELET));
    }

    public function testADiaperSensorRaisesItToo(): void
    {
        $this->alarm->byGateway[self::GATEWAY] = ['mode' => 'active', 'minSamples' => 1];

        $this->bridge->handleReceivedMessage($this->topic(), IngressFixtures::monitScan(self::GATEWAY, self::SENSOR, 'clean', rssi: -50));

        $alarms = $this->alarms();
        self::assertCount(1, $alarms);
        self::assertSame('diaper_sensor', $alarms[0]['deviceType']);
        self::assertSame('monit-mecs-pro-ble', $alarms[0]['payload']['source']['protocol']);
    }

    /** @param list<int> $readings */
    private function walkPast(array $readings): void
    {
        foreach ($readings as $index => $rssi) {
            $this->bridge->handleReceivedMessage($this->topic(), $this->scan($rssi, $index));
            $this->now += 1.0;
        }
    }

    /** @return list<array<string, mixed>> */
    private function alarms(): array
    {
        return array_values(array_filter(
            $this->mqtt->events,
            static fn(array $event): bool => ($event['payload']['type'] ?? '') === 'proximity_alarm',
        ));
    }

    private function topic(): string
    {
        return 'havicare-hub/null/0/gw/' . self::GATEWAY . '/raw';
    }

    /** Uma trama W6B; o `trigger_count` muda para cada leitura ser uma observação nova. */
    private function scan(int $rssi, int $count): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [[
                'type_code' => 7,
                'type' => 'bxp-button',
                'rssi' => $rssi,
                'connectable' => 1,
                'mac' => self::BRACELET,
                'frame_type' => 0,
                'passwd_verification' => 1,
                'alarm_status' => 1,
                'trigger_count' => 69 + $count,
                'device_id' => '000001',
                'adv_name' => 'MK Button',
                'batt_vol' => 98,
                'x_axis_data' => -4,
                'y_axis_data' => -20,
                'z_axis_data' => 1052,
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
