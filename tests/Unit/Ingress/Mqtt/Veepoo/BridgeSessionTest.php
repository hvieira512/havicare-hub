<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Device\PendingDownlinkQueue;
use Hub\Ingress\Mqtt\Veepoo\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\OneShotDownlinkQueue;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * A sessão do gateway com a pulseira, e o que dela chega ao hub.
 *
 * O gateway repete-a enquanto a ligação BLE durar -- é assim que o hub sabe que o aparelho
 * continua alcançável e que lhe pode entregar o que está em fila. Repetir o anúncio não é
 * ligar-se de novo, e a diferença tem de aparecer no histórico: senão um dia de pulseira ao
 * pulso enche-o de milhares de «Ligado» e o momento em que ela se ligou mesmo perde-se lá no
 * meio.
 */
final class BridgeSessionTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    public function testRepeatingTheSessionDoesNotAnnounceAConnectionEachTime(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        for ($i = 0; $i < 4; $i++) {
            $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        }

        self::assertCount(1, self::eventsOfType($mqtt, 'device.connected'));
    }

    /**
     * A pulseira sai de alcance sempre que quem a usa se afasta, e o gateway di-lo. Sem
     * tratar isto o ecrã continuava a mostrá-la ligada até o varrimento de aparelhos
     * parados dar por ela -- muito depois de já não haver ninguém a quem responder.
     */
    public function testLosingTheSessionAnnouncesTheDisconnection(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        $bridge->handleReceivedMessage(self::TOPIC, self::session(false));

        self::assertCount(1, self::eventsOfType($mqtt, 'device.disconnected'));

        $status = array_values(array_filter(
            $mqtt->statuses,
            static fn(array $e): bool => ($e['payload']['state'] ?? null) === 'offline',
        ));
        self::assertCount(1, $status);
    }

    /** Uma sessão nova depois de a perder volta a ser um acontecimento. */
    public function testConnectingAgainIsAnnouncedAgain(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach ([true, false, true] as $authenticated) {
            $bridge->handleReceivedMessage(self::TOPIC, self::session($authenticated));
        }

        self::assertCount(2, self::eventsOfType($mqtt, 'device.connected'));
    }

    /**
     * O valor tem de viajar com o comando.
     *
     * O que o hub põe em fila é o nome da operação, e o gateway precisa de saber se é para
     * ligar ou desligar. Sem o valor a viajar junto, desligar um interruptor chegava lá como
     * a ordem de o ligar -- e o «encontrar dispositivo» nunca poderia ser parado.
     */
    public function testTheDesiredValueTravelsWithTheCommand(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new OneShotDownlinkQueue(
            'config:heart_rate_continuous',
            ['command' => 'config:heart_rate_continuous', 'payload' => ['enabled' => false]],
        );

        $this->bridge($mqtt, $queue)->handleReceivedMessage(self::TOPIC, self::session(true));

        self::assertCount(1, $mqtt->gatewayCommands);
        self::assertSame(
            ['enabled' => false],
            $mqtt->gatewayCommands[0]['payload']['payload'],
        );
    }

    /** @return list<array<string, mixed>> */
    private static function eventsOfType(RecordingHubMqttBridge $mqtt, string $type): array
    {
        return array_values(array_filter(
            $mqtt->events,
            static fn(array $entry): bool => ($entry['payload']['type'] ?? null) === $type,
        ));
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

    private function bridge(RecordingHubMqttBridge $mqtt, ?PendingDownlinkQueue $queue = null): Bridge
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
            'havicare-hub/null/0/gw/+/raw',
        );
    }
}
