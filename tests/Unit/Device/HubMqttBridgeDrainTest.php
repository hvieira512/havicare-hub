<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\HubMqttBridge;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;

final class HubMqttBridgeDrainTest extends TestCase
{
    public function testDrainRunsThePublisherLoopToProcessPubacks(): void
    {
        $publisher = $this->createMock(MqttClient::class);
        $publisher->expects(self::once())->method('loopOnce');

        (new HubMqttBridge($publisher))->drainPublisher();
    }

    public function testDrainReconnectsWhenThePublisherLoopFails(): void
    {
        $publisher = $this->createMock(MqttClient::class);
        $publisher->method('loopOnce')->willThrowException(new \RuntimeException('server has gone away'));
        $publisher->method('isConnected')->willReturn(false);

        $reconnected = false;
        $bridge = new HubMqttBridge(
            $publisher,
            reconnectPublisher: function () use (&$reconnected): MqttClient {
                $reconnected = true;
                return $this->createMock(MqttClient::class);
            },
        );

        $bridge->drainPublisher();

        self::assertTrue($reconnected, 'uma falha no drain reconecta o publicador em vez de propagar');
    }

    /**
     * O `connect` é bloqueante no event loop que serve o HTTP e envia o sinal de vida ao
     * systemd. Sem recuo, o drain reconecta a cada segundo, o loop deixa de correr os outros
     * temporizadores, e o watchdog mata o processo -- o que a dashboard mostra como um 502.
     */
    public function testTheDrainBacksOffInsteadOfReconnectingOnEveryTick(): void
    {
        $publisher = $this->createMock(MqttClient::class);
        $publisher->method('loopOnce')->willThrowException(new \RuntimeException('server has gone away'));
        $publisher->method('isConnected')->willReturn(false);

        $attempts = 0;
        $bridge = new HubMqttBridge(
            $publisher,
            reconnectPublisher: function () use (&$attempts, $publisher): MqttClient {
                $attempts++;
                return $publisher;
            },
        );

        for ($i = 0; $i < 20; $i++) {
            $bridge->drainPublisher();
        }

        self::assertSame(1, $attempts, 'um broker que continua a largar a ligação não pode dar uma reconexão por tick');
    }
}
