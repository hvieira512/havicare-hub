<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * O sono da pulseira tem de se poder pedir.
 *
 * É a única grandeza sem outro caminho: os blocos de cinco minutos são relidos de cinco em
 * cinco minutos, mas o registo de sono entra uma vez só, quando o gateway arranca. Com o
 * gateway ligado há horas, a única maneira de obter a noite de ontem era matar o processo e
 * voltar a ligar -- três minutos, e a sessão BLE pelo caminho.
 *
 * A pulseira responde ao pedido a qualquer momento; faltava o hub sabê-lo pedir.
 */
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

    /**
     * O pedido fecha-se quando a trama chega, e ela produz duas capacidades: a noite e as
     * pontuações que o firmware lhe atribui. Sem as duas declaradas, o registo do comando
     * ficava à espera de uma resposta que já tinha chegado.
     */
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

    /**
     * Ler não é medir: o sono é um registo que o firmware já tem, como a bateria e os totais
     * do dia. Um `measure.` punha-o debaixo da vigilância que dá um pedido por falhado quando
     * a pulseira não produz leitura -- e não ter dormido não é uma falha da pulseira.
     */
    public function testItIsAReadAndNotAMeasurement(): void
    {
        $commands = DeviceCommandCatalog::commandsForFeature(self::PROTOCOL, 'sleep');

        self::assertStringStartsWith('read.', (string)$commands[0]['command']);
    }
}
