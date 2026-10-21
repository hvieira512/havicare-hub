<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/** O registo de sono só entra quando o gateway arranca, mas a pulseira responde ao pedido a qualquer momento. */
final class VeepooSleepRequestTest extends TestCase
{
    private const PROTOCOL = 'veepoo-ble';

    public function testTheSleepRecordCanBeRequested(): void
    {
        $commands = DeviceCommandCatalog::commandsForFeature(self::PROTOCOL, 'sleep');

        self::assertCount(1, $commands);
        self::assertSame('read.sleep', $commands[0]['command']);
        self::assertSame('request', $commands[0]['kind']);
    }

    /** A trama produz a noite e as pontuações, e as duas têm de estar declaradas para o registo do comando fechar. */
    public function testTheRequestExpectsBothHalvesOfTheRecord(): void
    {
        $commands = DeviceCommandCatalog::commandsForFeature(self::PROTOCOL, 'sleep');

        self::assertSame(['sleep', 'sleep_quality'], $commands[0]['expectedReplyTypes']);
    }

    /** E o catálogo tem de o declarar, senão o botão não chega a aparecer no ecrã. */
    public function testTheCapabilityIsDeclaredAsRequestable(): void
    {
        $sleep = array_values(array_filter(
            CapabilityCatalog::definitionsForDeviceType('bracelet'),
            static fn(array $definition): bool => $definition['key'] === 'sleep',
        ));

        self::assertCount(1, $sleep);
        self::assertTrue($sleep[0]['isRequestable']);
    }

    /** O sono é um registo que o firmware já tem, e um `measure.` daria o pedido por falhado quando não houve sono. */
    public function testItIsAReadAndNotAMeasurement(): void
    {
        $commands = DeviceCommandCatalog::commandsForFeature(self::PROTOCOL, 'sleep');

        self::assertStringStartsWith('read.', (string)$commands[0]['command']);
    }
}
