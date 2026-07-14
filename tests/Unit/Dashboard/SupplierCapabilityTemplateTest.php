<?php

namespace Tests\Unit\Dashboard;

use Hub\Domain\SupplierCapabilityTemplate;
use Hub\Domain\GenericModelCapabilityCatalog;
use PHPUnit\Framework\TestCase;

final class SupplierCapabilityTemplateTest extends TestCase
{
    public function testWatchCatalogIncludesPushMessageInSystemSettings(): void
    {
        $definitions = GenericModelCapabilityCatalog::definitionsForDeviceType('watch');
        $match = array_values(array_filter(
            $definitions,
            static fn(array $definition): bool => ($definition['key'] ?? '') === 'push_message'
        ));

        self::assertCount(1, $match);
        self::assertSame('settings_system', $match[0]['section'] ?? null);
        self::assertTrue($match[0]['isConfigurable'] ?? false);
        self::assertFalse($match[0]['isTelemetry'] ?? true);
        self::assertFalse($match[0]['isRequestable'] ?? true);
    }

    public function testWatchCatalogPlacesMedicationRemindersInAlarms(): void
    {
        $definitions = GenericModelCapabilityCatalog::definitionsForDeviceType('watch');
        $match = array_values(array_filter(
            $definitions,
            static fn(array $definition): bool => ($definition['key'] ?? '') === 'medication_reminders'
        ));

        self::assertCount(1, $match);
        self::assertSame('alarms', $match[0]['section'] ?? null);
        self::assertTrue($match[0]['isConfigurable'] ?? false);
        self::assertFalse($match[0]['isTelemetry'] ?? true);
    }

    public function testVivistarWatchTemplateMatchesProtocolCapabilities(): void
    {
        $actual = SupplierCapabilityTemplate::keysForSupplierDeviceType('Vivistar', 'watch');

        self::assertContains('battery', $actual);
        self::assertContains('activity', $actual);
        self::assertContains('heart_rate', $actual);
        self::assertContains('blood_pressure', $actual);
        self::assertContains('blood_oxygen', $actual);
        self::assertContains('temperature', $actual);
        self::assertContains('blood_sugar', $actual);
        self::assertContains('alarm_clock', $actual);
        self::assertContains('auto_vitals_interval', $actual);
        self::assertContains('phonebook', $actual);
        self::assertContains('push_message', $actual);
        self::assertContains('sos_contacts', $actual);
        self::assertContains('working_mode', $actual);
        self::assertNotContains('firmware_version', $actual);
        self::assertNotContains('device_status', $actual);
    }

    public function testWonlexWatchTemplateIncludesSupplierSpecificTelemetry(): void
    {
        $keys = SupplierCapabilityTemplate::keysForSupplierDeviceType('Wonlex', 'watch');

        self::assertContains('ecg', $keys);
        self::assertContains('hrv', $keys);
        self::assertContains('ppg', $keys);
        self::assertContains('rr_interval', $keys);
        self::assertContains('ecg_measurement_interval', $keys);
        self::assertContains('hrv_measurement_interval', $keys);
        self::assertContains('ppg_measurement_interval', $keys);
        self::assertContains('rr_interval_measurement_interval', $keys);
        self::assertNotContains('push_message', $keys);
    }

    public function testFourPTouchWatchTemplateReturnsSupportedSubset(): void
    {
        $actual = SupplierCapabilityTemplate::keysForSupplierDeviceType('4P Touch', 'watch');

        self::assertContains('battery', $actual);
        self::assertContains('activity', $actual);
        self::assertContains('heart_rate', $actual);
        self::assertContains('blood_pressure', $actual);
        self::assertContains('blood_oxygen', $actual);
        self::assertContains('temperature', $actual);
        self::assertContains('location', $actual);
        self::assertContains('alarm_clock', $actual);
        self::assertContains('medication_reminders', $actual);
        self::assertContains('push_message', $actual);
        self::assertContains('phonebook', $actual);
        self::assertContains('sos_contacts', $actual);
        self::assertContains('sound_profile', $actual);
    }

    public function testQinglanstRadarTemplateReturnsRadarTelemetry(): void
    {
        $actual = SupplierCapabilityTemplate::keysForSupplierDeviceType('Qinglanst', 'radar');

        self::assertSame(
            ['positions', 'vitals', 'position_minute_stats', 'vitals_minute_stats'],
            $actual
        );
    }
}
