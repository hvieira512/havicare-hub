<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Ncs;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Ingress\Mqtt\Ncs\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\FakeMqttSubscriber;

final class BridgeTest extends TestCase
{
    public function testUnregisteredNcsCreatesDashboardNotification(): void
    {
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
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            dashboardStore: $dashboardStore,
        );

        $bridge->handleReceivedMessage(
            '/voerka/hitcare/devices/bea6c3dd8e02/events',
            '{"from":"bea6c3dd8e02"}'
        );
    }
}


