<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Ncs;

use Hub\State\DeviceStoreContract;
use Hub\Ingress\Mqtt\Ncs\NcsBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\FakeMqttSubscriber;

final class BridgeTest extends TestCase
{
    /**
     * O âmbito do tópico é livre do lado da Voerka -- o manual usa `0` e os gateways
     * instalados usam nomes -- e por isso só um número conta como licença.
     */
    public function testUnregisteredNcsCreatesDashboardNotification(): void
    {
        $deviceStore = $this->createMock(DeviceStoreContract::class);
        $deviceStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(
                'bea6c3dd8e02',
                'voerka-ncs',
                '',
                'bea6c3dd8e02',
                'device_not_authorized',
                0
            );
        $bridge = new NcsBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            deviceStore: $deviceStore,
        );

        $bridge->handleReceivedMessage(
            '/voerka/hitcare/devices/bea6c3dd8e02/events',
            '{"from":"bea6c3dd8e02"}'
        );
    }

    /**
     * O âmbito de `/voerka/{âmbito}/devices/…` dá a licença, o único campo do assistente que não se
     * deduz do protocolo; é só uma pista, porque a atribuição sai da whitelist.
     */
    public function testUnregisteredNcsNotificationCarriesTheTopicLicense(): void
    {
        $deviceStore = $this->createMock(DeviceStoreContract::class);
        $deviceStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(
                'bea6c3dd8e02',
                'voerka-ncs',
                '',
                'bea6c3dd8e02',
                'device_not_authorized',
                1001
            );
        $bridge = new NcsBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            deviceStore: $deviceStore,
        );

        $bridge->handleReceivedMessage(
            '/voerka/1001/devices/bea6c3dd8e02/events',
            '{"from":"bea6c3dd8e02"}'
        );
    }

    public function testRegisteredNcsPublishesTheHelpCallEvent(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = new NcsBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                'gw-001' => IngressFixtures::device('Voerka', 'W812', 'ncs'),
            ]),
            $mqtt,
        );

        $bridge->handleReceivedMessage(
            '/voerka/1001/devices/gw-001/events',
            json_encode([
                'from' => 'gw-001',
                'type' => 6,
                'timestamp' => 372315009,
                'payload' => ['id' => '482929', 'key' => '8', 'code' => 4000, 'result' => 1],
            ], JSON_THROW_ON_ERROR)
        );

        // O raw sai sempre, e o evento é um help_call com o pagerId.
        self::assertCount(1, $mqtt->raw);
        self::assertCount(1, $mqtt->events);
        self::assertSame('gw-001', $mqtt->events[0]['imei']);
        self::assertSame('help_call', $mqtt->events[0]['payload']['type'] ?? null);
        self::assertSame('482929', $mqtt->events[0]['payload']['data']['pagerId'] ?? null);
    }
}
