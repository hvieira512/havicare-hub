<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Por que canal do MQTT sai cada coisa, e de onde vem essa decisão.
 *
 * Vinha de uma lista escrita à mão dentro do `DeviceHubServer` --
 * `['alarm', 'medication_intake', 'device_fault', 'help_call']` por `events`, tudo o resto
 * por `telemetry`. O `isEvent` que cada definição declara não era lido por ninguém nesse
 * caminho, e as duas fontes de verdade já discordavam: o `storage_environment` foi declarado
 * alerta e continuava a sair por telemetria.
 *
 * O que isto custava não era arrumação. O `events` publica a QoS 1 e o `telemetry` a QoS 0, e
 * uma dose falhada -- que não gera `medication_intake` nenhum, porque não houve toma a
 * registar -- só se anuncia pela mudança de estado de um alarme. O evento mais importante que
 * o dispensador produz era o único que se podia perder.
 */
final class EventChannelComesFromTheCatalogueTest extends TestCase
{
    /** O que o catálogo declara como acontecimento sai por `events`. */
    public function testWhatTheCatalogueCallsAnEventIsAnEvent(): void
    {
        foreach (['alarm', 'medication_intake', 'device_fault', 'help_call'] as $type) {
            self::assertTrue(CapabilityCatalog::isEventType($type), $type);
        }
    }

    /** E o que é leitura não é. */
    public function testAReadingIsNotAnEvent(): void
    {
        foreach (['battery', 'temperature', 'connectivity', 'medication_alarm_status'] as $type) {
            self::assertFalse(CapabilityCatalog::isEventType($type), $type);
        }
    }

    /**
     * Uma dose falhada é um acontecimento e tem de sair pelo canal com garantia de entrega.
     *
     * Não há `medication_intake` quando ninguém toma a medicação: o único sinal é o alarme a
     * mudar de estado numa notificação `0x04`.
     */
    public function testAMissedDoseTravelsAsAnEvent(): void
    {
        self::assertTrue(CapabilityCatalog::isEventType('medication_alarm_change'));
    }

    /** O alerta do ambiente também, que foi o caso que denunciou as duas fontes de verdade. */
    public function testTheStorageAlertTravelsAsAnEvent(): void
    {
        self::assertTrue(CapabilityCatalog::isEventType('storage_environment'));
    }

    /** Um tipo que o catálogo não conhece é leitura: é o que sempre foi o caso por omissão. */
    public function testAnUnknownTypeIsNotAnEvent(): void
    {
        self::assertFalse(CapabilityCatalog::isEventType('heartbeat'));
        self::assertFalse(CapabilityCatalog::isEventType(''));
    }

    /**
     * A bandeira tem de concordar entre tipos de aparelho, senão a pergunta não se pode fazer
     * só pela chave.
     *
     * A `help_call` existe em quatro catálogos e é acontecimento nos quatro. Uma chave que
     * fosse evento num aparelho e leitura noutro obrigaria o canal a saber de que aparelho
     * veio a mensagem, e a decisão deixaria de ser do catálogo.
     */
    public function testTheFlagCannotDisagreeBetweenDeviceTypes(): void
    {
        $seen = [];
        $disagreements = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            $key = (string)$definition['key'];
            $isEvent = ($definition['isEvent'] ?? false) === true;
            if (array_key_exists($key, $seen) && $seen[$key] !== $isEvent) {
                $disagreements[] = $key;
                continue;
            }
            $seen[$key] = $isEvent;
        }

        self::assertSame([], array_values(array_unique($disagreements)));
    }
}
