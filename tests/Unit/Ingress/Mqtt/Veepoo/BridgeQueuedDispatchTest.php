<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Device\PendingDownlink;
use Hub\Device\PendingDownlinkQueue;
use Hub\Ingress\Mqtt\Gateway\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * Entrega de um comando criado depois de a sessão já estar aberta.
 *
 * O gateway fica subscrito ao seu tópico de comandos enquanto correr, e por isso a pulseira
 * é alcançável entre sessões e não apenas no instante em que uma chega. Esperar pelo anúncio
 * seguinte custava ao utilizador até um intervalo de heartbeat inteiro por cada ordem dada
 * no ecrã -- fazer a pulseira vibrar demorava mais a sair do hub do que a pulseira leva a
 * desistir de vibrar.
 */
final class BridgeQueuedDispatchTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    public function testACommandQueuedAfterTheSessionIsDeliveredWithoutWaitingForTheNextOne(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue();
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        self::assertSame([], $mqtt->gatewayCommands, 'a fila estava vazia quando a sessão chegou');

        $queue->push('config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]]);
        $bridge->dispatchQueued();

        self::assertCount(1, $mqtt->gatewayCommands);
        self::assertSame('config:find_device', $mqtt->gatewayCommands[0]['payload']['operation']);
        self::assertSame(['enabled' => true], $mqtt->gatewayCommands[0]['payload']['payload']);
    }

    /**
     * Entregar não é executar, e o que não for confirmado fica em fila -- mas repeti-lo a
     * cada volta do temporizador seria um comando por segundo para sempre. O gateway ignora
     * a repetição da mesma chave durante minutos, por isso nunca a confirmaria e a entrega
     * nunca pararia.
     */
    public function testAnUnconfirmedCommandIsNotResentOnEveryTick(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue();
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        $queue->push('config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]]);

        for ($i = 0; $i < 5; $i++) {
            $bridge->dispatchQueued();
        }

        self::assertCount(1, $mqtt->gatewayCommands);
    }

    /** Sem sessão aberta não há a quem entregar, e insistir seria falar para o vazio. */
    public function testNothingIsDeliveredBeforeAnySession(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue();
        $queue->push('config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]]);

        $this->bridge($mqtt, $queue)->dispatchQueued();

        self::assertSame([], $mqtt->gatewayCommands);
    }

    /** E deixa de haver quando a ligação BLE cai: o gateway já não fala com a pulseira. */
    public function testNothingIsDeliveredAfterTheSessionIsLost(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue();
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        $bridge->handleReceivedMessage(self::TOPIC, self::session(false));

        $queue->push('config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]]);
        $bridge->dispatchQueued();

        self::assertSame([], $mqtt->gatewayCommands);
    }

    private static function queue(): PendingDownlinkQueue
    {
        return new class implements PendingDownlinkQueue {
            /** @var list<PendingDownlink> */
            private array $items = [];

            /** @param array<string, mixed>|null $command */
            public function push(string $bytes, ?array $command): void
            {
                $this->items[] = new PendingDownlink('', 'test-dedupe', $bytes, $command, 0, 0);
            }

            public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink
            {
                return new PendingDownlink($imei, 'test-dedupe', $bytes, $command, 0, $ttlSeconds);
            }

            /** @return list<PendingDownlink> */
            public function pendingFor(string $imei): array
            {
                return array_map(
                    static fn(PendingDownlink $d): PendingDownlink => new PendingDownlink(
                        $imei,
                        $d->dedupeKey,
                        $d->bytes,
                        $d->command,
                        $d->queuedAt,
                        $d->expiresAt,
                    ),
                    $this->items,
                );
            }

            public function remove(PendingDownlink $downlink): void
            {
                $this->items = [];
            }
        };
    }

    private static function session(bool $authenticated): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'session',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['authenticated' => $authenticated],
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(RecordingHubMqttBridge $mqtt, PendingDownlinkQueue $queue): Bridge
    {
        return new Bridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $mqtt,
            IngressFixtures::links(true),
            $queue,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
        );
    }
}
