<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Device\FeatureNormalizer;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/** O canal sai do `isEvent` do catálogo (`events` a QoS 1, `telemetry` a QoS 0), e uma lista à parte discordaria em silêncio. */
final class EventChannelComesFromTheCatalogueTest extends TestCase
{
    /** Cada tipo de evento dos relógios tem de estar no catálogo, senão sai em `telemetry`, a QoS 0, sem erro nenhum. */
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
