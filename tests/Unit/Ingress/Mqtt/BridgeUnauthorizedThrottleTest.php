<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\Ingress\Mqtt\DeviceBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O serviço corre meses e o travão guarda uma entrada por identidade: a que excede a janela tem de sair. */
final class BridgeUnauthorizedThrottleTest extends TestCase
{
    public function testIdentitiesOlderThanTheWindowAreForgotten(): void
    {
        $now = 1_000_000.0;
        $bridge = new ThrottleProbeBridge(
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
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


    /** O tamanho do travão, que é privado na base por não ser contrato de ninguém. */
    private static function throttled(DeviceBridge $bridge): int
    {
        $property = new \ReflectionProperty(DeviceBridge::class, 'lastUnauthorizedAt');

        return count($property->getValue($bridge));
    }
}

/** Expõe o registo de recusa, que é `protected` porque só as subclasses o chamam. */
final class ThrottleProbeBridge extends DeviceBridge
{
    public function reject(string $identity): void
    {
        $this->recordUnauthorizedDevice($identity, 'test-protocol', ident: $identity);
    }
}
