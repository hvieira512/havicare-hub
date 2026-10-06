<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\HubMqttBridge;
use PhpMqtt\Client\Exceptions\DataTransferException;
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
     * O `connect` bloqueia o loop que envia o sinal de vida ao systemd: sem recuo, o watchdog
     * mata o processo.
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

    /**
     * A biblioteca lê qualquer escrita parcial no socket não-bloqueante como queda, e reconectar
     * descarta a mensagem a meio de publicar numa ligação viva.
     */
    public function testAFailedWriteDoesNotReconnectBecauseTheConnectionIsAlive(): void
    {
        $publisher = $this->createMock(MqttClient::class);
        $publisher->method('loopOnce')->willThrowException(self::writeFailure());
        $publisher->method('isConnected')->willReturn(true);

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

        self::assertSame(0, $attempts, 'um socket de saída cheio não é uma ligação perdida');
    }

    /** Um socket que nunca aceita uma escrita está morto sem o dizer, e aí reconectar é o certo. */
    public function testWritesThatKeepFailingAreEventuallyTreatedAsALostConnection(): void
    {
        $publisher = $this->createMock(MqttClient::class);
        $publisher->method('loopOnce')->willThrowException(self::writeFailure());
        $publisher->method('isConnected')->willReturn(true);

        $attempts = 0;
        $bridge = new HubMqttBridge(
            $publisher,
            reconnectPublisher: function () use (&$attempts, $publisher): MqttClient {
                $attempts++;
                return $publisher;
            },
        );

        $bridge->drainPublisher();
        self::assertSame(0, $attempts, 'a primeira falha de escrita é tolerada');

        // Recua o início da série de falhas para além da tolerância, em vez de a esperar.
        (function (): void {
            $this->writeFailingSince -= 30.0;
        })->call($bridge);

        $bridge->drainPublisher();

        self::assertSame(1, $attempts, 'escritas a falhar sem parar acabam por valer uma reconexão');
    }

    private static function writeFailure(): DataTransferException
    {
        return new DataTransferException(
            DataTransferException::EXCEPTION_TX_DATA,
            'Sending data over the socket failed. Has it been closed?',
        );
    }
}
