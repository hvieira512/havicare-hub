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

    /**
     * O socket é não-bloqueante e a biblioteca lê qualquer escrita parcial como queda. Com o
     * buffer de saída cheio, um PINGREQ de dois bytes devolve zero e chega para isso -- e
     * reconectar ali descarta a mensagem que estava a ser publicada, numa ligação que está viva.
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
