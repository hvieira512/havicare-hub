<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * O canal do MQTT sai do `isEvent` do catálogo: acontecimentos por `events`, a QoS 1, leituras
 * por `telemetry`, a QoS 0. Era uma lista escrita à mão que já discordava do catálogo.
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
     * Alteração de contrato: quem subscrevia `.../watch/+/telemetry` à espera do relatório de
     * sistema deixa de o receber aí. Registada na tabela do capítulo 8.
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

    /** Não há `medication_intake` numa dose falhada: esta mudança de estado é o único sinal. */
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

    /** Sem concordar entre aparelhos, a pergunta não se poderia fazer só pela chave. */
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
