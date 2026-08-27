<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Moko\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * Routing a W6 bracelet through the MOKO gateway ingress.
 *
 * At rest a W6 advertises its accelerometer frame, which the gateway types `bxp-acc`. A
 * press instead switches on the Eddystone-UID slot carrying that mode's instance id, for
 * thirty seconds, with no cumulative counter to compare against.
 */
final class BridgeW6Test extends TestCase
{
    private const GATEWAY = 'c5e390f30bce';
    private const BRACELET = 'fa05c2c70fc6';

    private function accPayload(): string
    {
        return $this->payload([
            'type_code' => 5,
            'type' => 'bxp-acc',
            'rssi' => -33,
            'mac' => self::BRACELET,
            'x_axis_data' => -956,
            'y_axis_data' => 272,
            'z_axis_data' => 140,
            'batt_vol' => 2808,
        ]);
    }

    private function pressPayload(string $instance): string
    {
        return $this->payload([
            'type_code' => 1,
            'type' => 'eddystone-uid',
            'rssi' => -44,
            'mac' => self::BRACELET,
            'namespace' => '00000000fa05c2c70fc6',
            'instance' => $instance,
        ]);
    }

    /** @param array<string, mixed> $observation */
    private function payload(array $observation): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [$observation],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, array<string, string>> $extraDevices
     */
    private function bridge(
        ?DashboardStoreContract $dashboardStore = null,
        ?RecordingHubMqttBridge $mqtt = null,
        array $extraDevices = [],
    ): Bridge {
        $path = tempnam(sys_get_temp_dir(), 'moko-w6-whitelist-');
        file_put_contents($path, json_encode([
            self::GATEWAY => ['supplier' => 'MOKO', 'model' => 'MKGW4', 'deviceType' => 'gateway', 'licenseId' => '1001', 'company' => 'hitcare'],
        ] + $extraDevices, JSON_THROW_ON_ERROR));

        $links = new class implements GatewayDeviceLinkLookup {
            public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool
            {
                return true;
            }
        };

        return new Bridge(
            new FakeMqttSubscriber(),
            new Whitelist($path),
            $mqtt ?? new RecordingHubMqttBridge(),
            $links,
            new ArrayObservationStateStore(),
            dashboardStore: $dashboardStore,
        );
    }

    /** @return array<string, array<string, string>> */
    private function registered(): array
    {
        return [
            self::BRACELET => ['supplier' => 'MOKO', 'model' => 'W6', 'deviceType' => 'bracelet', 'licenseId' => '1001', 'company' => 'hitcare'],
        ];
    }

    private function deliver(Bridge $bridge, string $payload): void
    {
        $bridge->handleReceivedMessage('havicare-hub/null/0/gw/' . self::GATEWAY . '/raw', $payload);
    }

    /** @param list<array<string, mixed>> $published @return list<array<string, mixed>> */
    private function forBracelet(array $published): array
    {
        return array_values(array_filter(
            $published,
            static fn(array $entry): bool => $entry['imei'] === self::BRACELET,
        ));
    }

    public function testAnUnregisteredW6RaisesADashboardNotification(): void
    {
        $dashboardStore = $this->createMock(DashboardStoreContract::class);
        $dashboardStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(self::BRACELET, 'moko-w6', 'W6', self::BRACELET, 'device_not_authorized', 0);

        $this->deliver($this->bridge($dashboardStore), $this->accPayload());
    }

    public function testARegisteredW6IsSeenInsteadOfRejected(): void
    {
        // O gateway também se anuncia a si próprio, por isso guardam-se todas as chamadas
        // e olha-se só para a da pulseira.
        $seen = [];
        $dashboardStore = $this->createMock(DashboardStoreContract::class);
        $dashboardStore->expects(self::never())->method('recordRejectedDevice');
        $dashboardStore->method('deviceSeen')
            ->willReturnCallback(function (string $imei, array $state) use (&$seen): void {
                $seen[$imei] = $state;
            });

        $this->deliver(
            $this->bridge($dashboardStore, extraDevices: $this->registered()),
            $this->accPayload(),
        );

        self::assertArrayHasKey(self::BRACELET, $seen);
        self::assertSame('moko-w6', $seen[self::BRACELET]['protocol']);
        self::assertSame('bracelet', $seen[self::BRACELET]['deviceType']);
        self::assertSame('1', $seen[self::BRACELET]['online']);
    }

    public function testTheAccelerometerFramePublishesBatteryAndMotion(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->deliver(
            $this->bridge(null, $mqtt, $this->registered()),
            $this->accPayload(),
        );

        $types = array_column($this->forBracelet($mqtt->telemetry), 'type');
        self::assertContains('battery', $types);
        self::assertContains('motion', $types);
    }

    public function testEachPressModePublishesItsOwnHelpCall(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge(null, $mqtt, $this->registered());

        $this->deliver($bridge, $this->pressPayload('000000000011'));
        $this->deliver($bridge, $this->pressPayload('000000000012'));
        $this->deliver($bridge, $this->pressPayload('000000000013'));

        $events = $this->forBracelet($mqtt->events);
        self::assertSame(['help_call', 'help_call', 'help_call'], array_column($events, 'type'));
        self::assertSame(
            ['single', 'double', 'triple'],
            array_map(static fn(array $e): string => $e['payload']['data']['pressType'], $events),
        );
        self::assertSame(self::GATEWAY, $events[0]['payload']['source']['gatewayId']);
    }

    /**
     * The slot keeps advertising for thirty seconds and every gateway in range repeats it,
     * so one press must not become thirty help calls.
     */
    public function testTheRepeatedFrameOfOnePressRaisesOneHelpCall(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge(null, $mqtt, $this->registered());

        for ($i = 0; $i < 5; $i++) {
            $this->deliver($bridge, $this->pressPayload('000000000011'));
        }

        self::assertCount(1, $this->forBracelet($mqtt->events));
    }

    /**
     * The identity slot is advertised permanently, so it must never read as a press.
     */
    public function testTheIdentitySlotRaisesNoHelpCall(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->deliver(
            $this->bridge(null, $mqtt, $this->registered()),
            $this->pressPayload('000000000001'),
        );

        self::assertSame([], $this->forBracelet($mqtt->events));
    }
}
