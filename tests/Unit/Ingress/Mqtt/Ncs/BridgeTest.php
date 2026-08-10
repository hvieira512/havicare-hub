<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Ncs;

use Hub\Dashboard\DashboardStoreContract;
use Hub\HubMqttBridge;
use Hub\Ingress\Mqtt\Ncs\Bridge;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\FakeMqttSubscriber;

final class BridgeTest extends TestCase
{
    public function testUnregisteredNcsCreatesDashboardNotification(): void
    {
        $whitelistPath = tempnam(sys_get_temp_dir(), 'ncs-whitelist-');
        file_put_contents($whitelistPath, '{}');
        $dashboardStore = $this->createMock(DashboardStoreContract::class);
        $dashboardStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(
                'bea6c3dd8e02',
                'voerka-ncs',
                '',
                'bea6c3dd8e02',
                'device_not_authorized'
            );
        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            new Whitelist($whitelistPath),
            new RecordingHubMqttBridge(),
            dashboardStore: $dashboardStore,
        );

        $bridge->handleReceivedMessage(
            '/voerka/hitcare/devices/bea6c3dd8e02/events',
            '{"from":"bea6c3dd8e02"}'
        );

        @unlink($whitelistPath);
    }
}


