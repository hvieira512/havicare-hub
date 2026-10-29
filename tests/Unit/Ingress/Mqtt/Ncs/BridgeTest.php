<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Ncs;

use Hub\Ingress\Mqtt\Ncs\NcsBridge;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

final class BridgeTest extends TestCase
{
    /** O âmbito do tópico é livre na Voerka (o manual usa `0`, as instalações usam nomes), e só um número conta como licença. */
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

    /** O âmbito de `/voerka/{âmbito}/devices/…` dá a licença só como pista, porque a atribuição sai da whitelist. */
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

    /** Dois gateways da mesma licença ouvem o mesmo clique: o evento sai uma vez, o raw sai pelos dois. */
    public function testTheSameClickHeardByTwoGatewaysPublishesOneEvent(): void
    {
        $now = 1000.0;
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridgeWithTwoGateways($mqtt, function () use (&$now): float {
            return $now;
        });

        $bridge->handleReceivedMessage('/voerka/1001/devices/gw-001/events', $this->click('gw-001', '642295'));
        $now += 0.05;
        $bridge->handleReceivedMessage('/voerka/1001/devices/gw-002/events', $this->click('gw-002', '642295'));

        self::assertCount(2, $mqtt->raw);
        self::assertCount(1, $mqtt->events);
        self::assertSame('gw-001', $mqtt->events[0]['imei']);
    }

    /** Carregar outra vez no mesmo pager é um pedido novo, e o cliente conta-os. */
    public function testPressingThePagerAgainAfterTheWindowPublishesAgain(): void
    {
        $now = 1000.0;
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridgeWithTwoGateways($mqtt, function () use (&$now): float {
            return $now;
        });

        $bridge->handleReceivedMessage('/voerka/1001/devices/gw-001/events', $this->click('gw-001', '642295'));
        $now += 1.0;
        $bridge->handleReceivedMessage('/voerka/1001/devices/gw-002/events', $this->click('gw-002', '642295'));
        $bridge->handleReceivedMessage('/voerka/1001/devices/gw-001/events', $this->click('gw-001', '482929'));

        self::assertCount(3, $mqtt->events);
    }

    private function bridgeWithTwoGateways(RecordingHubMqttBridge $mqtt, callable $clock): NcsBridge
    {
        return new NcsBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                'gw-001' => IngressFixtures::device('Voerka', 'W812', 'ncs'),
                'gw-002' => IngressFixtures::device('Voerka', 'W812', 'ncs'),
            ]),
            $mqtt,
            clock: $clock,
        );
    }

    private function click(string $gateway, string $pagerId): string
    {
        return json_encode([
            'from' => $gateway,
            'type' => 6,
            'payload' => ['id' => $pagerId, 'key' => '8', 'code' => 4000, 'result' => 1],
        ], JSON_THROW_ON_ERROR);
    }
}
