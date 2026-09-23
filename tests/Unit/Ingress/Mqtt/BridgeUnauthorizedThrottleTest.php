<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\Ingress\Mqtt\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * O travão que espaça os avisos de dispositivo não registado guarda uma entrada por
 * identidade, e essa entrada tem de acabar por sair.
 *
 * As identidades não são nossas: vêm do tópico, e o serviço corre meses. Podar pelo tempo não
 * custa comportamento nenhum -- uma entrada mais velha do que a janela já deixava passar o
 * aviso seguinte.
 */
final class BridgeUnauthorizedThrottleTest extends TestCase
{
    public function testIdentitiesOlderThanTheWindowAreForgotten(): void
    {
        $now = 1_000_000.0;
        $bridge = new ThrottleProbeBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            'test/topic',
            'test',
            null,
            null,
            null,
            static function () use (&$now): float {
                return $now;
            },
        );

        foreach (['AA:00', 'BB:01', 'CC:02', 'DD:03', 'EE:04'] as $identity) {
            $bridge->reject($identity);
        }
        self::assertSame(5, self::throttled($bridge), 'as cinco identidades entram no travão');

        // Muito depois da janela: nenhuma das cinco continua a travar coisa nenhuma.
        $now += 3600.0;
        $bridge->reject('FF:05');

        self::assertSame(
            1,
            self::throttled($bridge),
            'as identidades fora da janela têm de ser esquecidas, senão o mapa cresce com o tempo de vida do processo',
        );
    }

    /**
     * Podar não pode custar o travão: uma identidade repetida dentro da janela continua a
     * dar um registo só, que é a razão de o mapa existir.
     */
    public function testAnIdentityInsideTheWindowIsStillThrottled(): void
    {
        $now = 1_000_000.0;
        $store = $this->createMock(\Hub\Dashboard\DashboardStoreContract::class);
        $store->expects(self::once())->method('recordRejectedDevice');
        $bridge = new ThrottleProbeBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            'test/topic',
            'test',
            null,
            $store,
            null,
            static function () use (&$now): float {
                return $now;
            },
        );

        $bridge->reject('AA:00');
        $now += 5.0;
        $bridge->reject('AA:00');
        $now += 5.0;
        $bridge->reject('AA:00');
    }

    /** O tamanho do travão, que é privado na base por não ser contrato de ninguém. */
    private static function throttled(Bridge $bridge): int
    {
        $property = new \ReflectionProperty(Bridge::class, 'lastUnauthorizedAt');

        return count($property->getValue($bridge));
    }
}

/** Expõe o registo de recusa, que é `protected` porque só as subclasses o chamam. */
final class ThrottleProbeBridge extends Bridge
{
    protected function handleMessage(string $topic, string $payload): void
    {
    }

    public function reject(string $identity): void
    {
        $this->recordUnauthorizedDevice($identity, 'test-protocol', ident: $identity);
    }
}
