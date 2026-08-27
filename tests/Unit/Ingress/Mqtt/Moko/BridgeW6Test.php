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
 * At rest a W6 advertises its accelerometer frame, which the gateway types
 * `bxp-acc` rather than the `bxp-button` its presses arrive on. Until someone
 * registers it, every sighting has to surface as a dashboard notification --
 * that is the only way anyone learns it is there.
 */
final class BridgeW6Test extends TestCase
{
    private const GATEWAY = 'c5e390f30bce';
    private const BRACELET = 'fa05c2c70fc6';

    private function scanPayload(): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [[
                'type_code' => 5,
                'type' => 'bxp-acc',
                'rssi' => -33,
                'connectable' => 1,
                'mac' => self::BRACELET,
                'adv_data' => '020106020a031816abfe60000a0100013e40edc007c00b2800fa05c2c70fc6',
                'rsp_data' => '',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, array<string, string>> $extraDevices */
    private function bridge(DashboardStoreContract $dashboardStore, array $extraDevices = []): Bridge
    {
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
            new RecordingHubMqttBridge(),
            $links,
            new ArrayObservationStateStore(),
            dashboardStore: $dashboardStore,
        );
    }

    public function testAnUnregisteredW6RaisesADashboardNotification(): void
    {
        $dashboardStore = $this->createMock(DashboardStoreContract::class);
        $dashboardStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(self::BRACELET, 'moko-w6', 'W6', self::BRACELET, 'device_not_authorized', 0);

        $this->bridge($dashboardStore)->handleReceivedMessage(
            'havicare-hub/null/0/gw/' . self::GATEWAY . '/raw',
            $this->scanPayload(),
        );
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

        $this->bridge($dashboardStore, [
            self::BRACELET => ['supplier' => 'MOKO', 'model' => 'W6', 'deviceType' => 'bracelet', 'licenseId' => '1001', 'company' => 'hitcare'],
        ])->handleReceivedMessage(
            'havicare-hub/null/0/gw/' . self::GATEWAY . '/raw',
            $this->scanPayload(),
        );

        self::assertArrayHasKey(self::BRACELET, $seen);
        self::assertSame('moko-w6', $seen[self::BRACELET]['protocol']);
        self::assertSame('bracelet', $seen[self::BRACELET]['deviceType']);
        self::assertSame('1', $seen[self::BRACELET]['online']);
    }
}
