<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\DeviceHubServer;
use Hub\HubDownlinkSubscriber;
use Hub\Ingress\Mqtt\Bridge;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * O recuo entre reconexões, quando o broker aceita a ligação e a larga logo a seguir.
 *
 * É o caso que um `client_id` duplicado produz -- o segundo cliente a chegar expulsa o
 * primeiro, os dois reconectam, e expulsam-se um ao outro sem fim. O `connect` devolvia
 * sempre sucesso, o recuo era reposto antes de alguma vez crescer, e saíam mais de uma
 * reconexão por segundo.
 *
 * Isso não seria mais do que ruído no log se o MQTT tivesse loop próprio. Não tem: o
 * `IngressRunner` agenda os ticks no mesmo event loop que serve o HTTP, e o `connect` é
 * bloqueante. Cada tentativa parava a dashboard, o que se via em pedidos de 88 a 400 ms
 * com `duration_ms: 0` registado pelo próprio hub -- tempo passado à espera do loop, não
 * a trabalhar.
 */
final class BridgeReconnectBackoffTest extends TestCase
{
    public function testAConnectionThatDiesOnArrivalDoesNotReconnectOnEveryTick(): void
    {
        $whitelistPath = tempnam(sys_get_temp_dir(), 'bridge-backoff-');
        file_put_contents($whitelistPath, '{}');

        $attempts = 0;
        $bridge = new AlwaysFailingBridge(
            new DeadOnArrivalSubscriber(),
            new Whitelist($whitelistPath),
            new RecordingHubMqttBridge(),
            'test/topic',
            'test',
            static function () use (&$attempts): MqttClient {
                $attempts++;
                return new DeadOnArrivalSubscriber();
            },
        );

        // Vinte ticks no mesmo instante. O primeiro descobre a ligação morta e reconecta;
        // os outros dezanove caem dentro da janela de recuo e não fazem nada.
        for ($i = 0; $i < 20; $i++) {
            $bridge->tick();
        }

        self::assertSame(
            1,
            $attempts,
            'uma ligação que morre à chegada não pode repor o recuo e reconectar a cada tick',
        );

        @unlink($whitelistPath);
    }

    /**
     * O mesmo, no `HubDownlinkSubscriber`.
     *
     * A lógica de reconexão estava escrita duas vezes, linha por linha, e as duas cópias
     * tinham o defeito. Passou a ser uma só -- o `ReconnectsOnLoopFailure` --, e este teste
     * existe para a segunda utilizadora não voltar a divergir em silêncio: era ela que
     * fazia 148 das 158 reconexões por minuto depois de o `Bridge` já estar corrigido.
     */
    public function testTheDownlinkSubscriberBacksOffTheSameWay(): void
    {
        $attempts = 0;
        $subscriber = new HubDownlinkSubscriber(
            new DeadOnArrivalSubscriber(),
            $this->createMock(DeviceHubServer::class),
            '',
            static function () use (&$attempts): MqttClient {
                $attempts++;
                return new DeadOnArrivalSubscriber();
            },
        );

        for ($i = 0; $i < 20; $i++) {
            $subscriber->tick();
        }

        self::assertSame(1, $attempts);
    }
}

/** Um cliente cuja ligação nunca chega a servir: o `loopOnce` estoira como um socket em EOF. */
final class DeadOnArrivalSubscriber extends MqttClient
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 1883, 'dead-on-arrival');
    }

    public function subscribe(string $topicFilter, ?callable $callback = null, int $qualityOfService = 0): void
    {
    }

    public function loopOnce(float $startedAt, bool $allowSleep = false, int $sleepMicroseconds = 100000): void
    {
        throw new \RuntimeException('The socket has reached EOF');
    }

    public function isConnected(): bool
    {
        return false;
    }
}

final class AlwaysFailingBridge extends Bridge
{
    protected function handleMessage(string $topic, string $payload): void
    {
    }
}
