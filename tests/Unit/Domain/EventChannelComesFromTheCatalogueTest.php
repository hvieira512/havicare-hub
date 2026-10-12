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

    /** E o que é leitura não é. */
    public function testAReadingIsNotAnEvent(): void
    {
        foreach (['battery', 'temperature', 'connectivity', 'medication_alarm_status'] as $type) {
            self::assertFalse(CapabilityCatalog::isEventType($type), $type);
        }
    }

    /** Um tipo que o catálogo não conhece é leitura. */
    public function testAnUnknownTypeIsNotAnEvent(): void
    {
        self::assertFalse(CapabilityCatalog::isEventType('heartbeat'));
        self::assertFalse(CapabilityCatalog::isEventType(''));
    }
}
