<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Device\FeatureNormalizer;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * O canal do MQTT sai do `isEvent` do catálogo: acontecimentos por `events`, a QoS 1, leituras
 * por `telemetry`, a QoS 0. Uma lista à parte discordaria do catálogo sem dar erro.
 */
final class EventChannelComesFromTheCatalogueTest extends TestCase
{
    /** O que o catálogo declara como acontecimento sai por `events`. */
    public function testWhatTheCatalogueCallsAnEventIsAnEvent(): void
    {
        foreach (['medication_intake', 'device_fault', 'help_call', 'fall', 'apnea'] as $type) {
            self::assertTrue(CapabilityCatalog::isEventType($type), $type);
        }
    }

    /**
     * O que os relógios disparam sai com o tipo do que aconteceu, e cada um tem de estar no
     * catálogo: um que falte sai em `telemetry`, a QoS 0, sem erro nenhum.
     */
    public function testEveryWatchAlarmIsADeclaredEvent(): void
    {
        $payload = [
            'sos' => true, 'lowBattery' => true, 'fall' => true, 'removeAlarm' => true,
            'outFenceAlarm' => true, 'inFenceAlarm' => true, 'abnormalHeartRateAlarm' => true,
        ];
        foreach (FeatureNormalizer::alarms($payload) as $alarm) {
            self::assertTrue(CapabilityCatalog::isEventType($alarm['feature']), $alarm['feature']);
        }
    }

    /** Os sacos de antes já não existem: o tipo diz o que aconteceu. */
    public function testTheOldAlarmBagsAreGone(): void
    {
        foreach (['alarm', 'vitals_alarm', 'presence_event'] as $type) {
            self::assertFalse(CapabilityCatalog::isEventType($type), $type);
        }
    }

    /** O relatório de sistema do relógio sai por `events`, e não por `telemetry` (capítulo 8). */
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

    public function testTheStorageAlertTravelsAsAnEvent(): void
    {
        self::assertTrue(CapabilityCatalog::isEventType('storage_environment'));
    }

    /** Um tipo que o catálogo não conhece é leitura. */
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
