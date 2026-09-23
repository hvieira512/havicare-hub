<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Por que canal do MQTT sai cada coisa, e de onde vem essa decisão.
 *
 * A decisão é do `isEvent` que cada definição declara, e não de uma lista escrita à mão no
 * `DeviceHubServer`. Não é arrumação: o `events` publica a QoS 1 e o `telemetry` a QoS 0, e
 * pelo canal errado uma dose falhada é um acontecimento que se pode perder.
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

    /**
     * O `device_state` dos relógios muda de canal, e isso é uma alteração de contrato.
     *
     * Está declarado como acontecimento desde sempre, mas a lista à mão não o incluía e ele
     * saía por `telemetry`. Prende-se aqui porque quem subscrevesse `.../watch/+/telemetry`
     * deixa de o receber aí. A tabela do [contrato MQTT](docs/08-contrato-mqtt.md) regista-a.
     */
    public function testTheWatchSystemReportMovedToTheEventChannel(): void
    {
        self::assertTrue(CapabilityCatalog::isEventType('device_state'));
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
     * Uma chave que fosse evento num aparelho e leitura noutro obrigaria o canal a saber de
     * que aparelho veio a mensagem, e a decisão deixaria de ser do catálogo.
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
