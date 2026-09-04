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
    /**
     * O âmbito do tópico é livre do lado da Voerka -- o manual usa `0` e os gateways
     * instalados usam nomes -- e por isso só um número conta como licença.
     */
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
                'device_not_authorized',
                0
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

    /**
     * A notificação de um W812 desconhecido leva a licença do tópico.
     *
     * O âmbito de `/voerka/{âmbito}/devices/…` é configurável no gateway, e pô-lo a valer a
     * licença dá à dashboard o único campo do assistente de registo que não se deduz do
     * protocolo. Continua a ser só uma pista: a atribuição sai da whitelist, porque o tópico
     * é escrito por quem publica no broker.
     */
    public function testUnregisteredNcsNotificationCarriesTheTopicLicense(): void
    {
        $dashboardStore = $this->createMock(DashboardStoreContract::class);
        $dashboardStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(
                'bea6c3dd8e02',
                'voerka-ncs',
                '',
                'bea6c3dd8e02',
                'device_not_authorized',
                1001
            );
        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            dashboardStore: $dashboardStore,
        );

        $bridge->handleReceivedMessage(
            '/voerka/1001/devices/bea6c3dd8e02/events',
            '{"from":"bea6c3dd8e02"}'
        );
    }
}
