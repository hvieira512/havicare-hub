<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\HubMqttBridge;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;

/** A instância de dev lê os gateways reais, e sem este interruptor as ordens dela iriam para os aparelhos reais. */
final class GatewayCommandsSwitchTest extends TestCase
{
    public function testWithGatewayCommandsOffNothingIsPublishedToTheGateway(): void
    {
        $publisher = $this->createMock(MqttClient::class);
        $publisher->expects(self::never())->method('publish');

        (new HubMqttBridge($publisher, gatewayCommands: false))
            ->publishGatewayCommand('havicare-hub/null/0/gw/bef341903987/cmd', ['operation' => 'calibrateClock']);
    }

    public function testByDefaultTheCommandGoesOut(): void
    {
        $publisher = $this->createMock(MqttClient::class);
        $publisher->expects(self::once())->method('publish');

        (new HubMqttBridge($publisher))
            ->publishGatewayCommand('havicare-hub/null/0/gw/bef341903987/cmd', ['operation' => 'calibrateClock']);
    }
}
