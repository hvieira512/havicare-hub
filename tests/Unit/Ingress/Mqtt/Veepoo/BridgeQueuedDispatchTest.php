<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Device\RedisPendingDownlinkQueue;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\InMemoryRedisClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\VeepooFixtures;

/** A pulseira é alcançável entre sessões, e esperar pelo anúncio seguinte custaria até um intervalo de heartbeat por ordem. */
final class BridgeQueuedDispatchTest extends TestCase
{
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    public function testACommandQueuedAfterTheSessionIsDeliveredWithoutWaitingForTheNextOne(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session(true));
        self::assertSame([], $mqtt->gatewayCommands, 'a fila estava vazia quando a sessão chegou');

        $queue->enqueue(self::BRACELET, 'config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]], 300);
        $bridge->dispatchQueued();

        self::assertCount(1, $mqtt->gatewayCommands);
        self::assertSame('config:find_device', $mqtt->gatewayCommands[0]['payload']['operation']);
        self::assertSame(['enabled' => true], $mqtt->gatewayCommands[0]['payload']['payload']);
    }

    /** O gateway ignora a mesma chave durante minutos, e repetir a cada volta o que não foi confirmado nunca pararia a entrega. */
    public function testAnUnconfirmedCommandIsNotResentOnEveryTick(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session(true));
        $queue->enqueue(self::BRACELET, 'config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]], 300);

        for ($i = 0; $i < 5; $i++) {
            $bridge->dispatchQueued();
        }

        self::assertCount(1, $mqtt->gatewayCommands);
    }

    /** Sem sessão aberta não há a quem entregar, e insistir seria falar para o vazio. */
    public function testNothingIsDeliveredBeforeAnySession(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::BRACELET, 'config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]], 300);

        VeepooFixtures::bridge($mqtt, $queue)->dispatchQueued();

        self::assertSame([], $mqtt->gatewayCommands);
    }

    /** E deixa de haver quando a ligação BLE cai: o gateway já não fala com a pulseira. */
    public function testNothingIsDeliveredAfterTheSessionIsLost(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session(true));
        IngressFixtures::relay($bridge, self::TOPIC, self::session(false));

        $queue->enqueue(self::BRACELET, 'config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]], 300);
        $bridge->dispatchQueued();

        self::assertSame([], $mqtt->gatewayCommands);
    }

    /** A chave só se marca entregue depois de a publicação passar, para um gateway inalcançável não calar a ordem trinta segundos. */
    public function testACommandWhosePublishFailsIsRetriedOnTheNextTick(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $mqtt = new class extends RecordingHubMqttBridge {
            public bool $failing = true;

            public function publishGatewayCommand(string $topic, array $payload): void
            {
                if ($this->failing) {
                    throw new \RuntimeException('gateway unreachable');
                }
                parent::publishGatewayCommand($topic, $payload);
            }
        };
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session(true));
        $queue->enqueue(self::BRACELET, 'config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]], 300);

        try {
            $bridge->dispatchQueued();
        } catch (\RuntimeException) {
            // O runner engole-a; aqui só interessa o que ficou marcado.
        }
        self::assertSame([], $mqtt->gatewayCommands, 'a primeira tentativa não chegou a sair');

        $mqtt->failing = false;
        $bridge->dispatchQueued();

        self::assertCount(1, $mqtt->gatewayCommands, 'a ordem que nunca saiu tem de voltar a ser tentada');
    }

    /** O hub repete a cada sessenta segundos o que espera confirmação, e o gateway reconhece a reentrega pelo identificador. */
    public function testTheRequestIdentityTravelsToTheGateway(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session(true));
        $queue->enqueue(self::BRACELET, 'measure.heartRate.start', ['id' => 'a1b2c3d4'], 300);
        $bridge->dispatchQueued();

        self::assertCount(1, $mqtt->gatewayCommands);
        self::assertSame('a1b2c3d4', $mqtt->gatewayCommands[0]['payload']['commandId']);
        self::assertSame('measure.heartRate.start', $mqtt->gatewayCommands[0]['payload']['operation']);
    }


    private static function session(bool $authenticated): string
    {
        return VeepooFixtures::message('session', ['authenticated' => $authenticated]);
    }
}
