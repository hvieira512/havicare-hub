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
}
