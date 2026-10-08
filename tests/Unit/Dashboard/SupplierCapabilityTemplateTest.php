<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Domain\SupplierCapabilityTemplate;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

final class SupplierCapabilityTemplateTest extends TestCase
{
    public function testWatchCatalogIncludesPushMessageInSystemSettings(): void
    {
        $definitions = CapabilityCatalog::definitionsForDeviceType('watch');
        $match = array_values(array_filter(
            $definitions,
            static fn(array $definition): bool => ($definition['key'] ?? '') === 'push_message'
        ));

        self::assertCount(1, $match);
        self::assertSame('settings_system', $match[0]['section'] ?? null);
        self::assertFalse($match[0]['isConfigurable'] ?? true);
        self::assertFalse($match[0]['isTelemetry'] ?? true);
        self::assertTrue($match[0]['isRequestable'] ?? false);
    }

    public function testWatchCatalogPlacesMedicationRemindersInReminders(): void
    {
        $definitions = CapabilityCatalog::definitionsForDeviceType('watch');
        $match = array_values(array_filter(
            $definitions,
            static fn(array $definition): bool => ($definition['key'] ?? '') === 'medication_reminders'
        ));

        self::assertCount(1, $match);
        self::assertSame('reminders', $match[0]['section'] ?? null);
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
        self::assertContains('call_whitelist', $actual);
        self::assertContains('push_message', $actual);
        self::assertContains('sos_contacts', $actual);
        self::assertContains('working_mode', $actual);
        self::assertNotContains('phonebook', $actual);
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
        self::assertContains('phonebook', $keys);
        self::assertContains('sos_contacts', $keys);
        self::assertContains('whitelist_enabled', $keys);
        self::assertNotContains('call_whitelist', $keys);
        self::assertNotContains('call_in_restriction', $keys);
        self::assertContains('push_message', $keys);
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
            [
                'fall',
                'heart_rate_high',
                'heart_rate_low',
                'breath_rate_high',
                'breath_rate_low',
                'apnea',
                'weak_vital_signs',
                'zone_entry',
                'zone_exit',
                'heart_rate',
                'breath_rate',
                'sleep_state',
                'presence',
                'position_minute_stats',
                'vitals_minute_stats',
            ],
            $actual
        );
    }

    /** Os nomes são os que o normalizador publica: `help_call`, e não `pager_call`, e o `reset` que a fecha. */
    public function testVoerkaNcsTemplateReturnsHelpCallAndReset(): void
    {
        $actual = SupplierCapabilityTemplate::keysForSupplierDeviceType('Voerka', 'ncs');

        self::assertSame(['help_call', 'reset'], $actual);
    }

    public function testMokoGatewayCapabilitiesAreModelSpecific(): void
    {
        self::assertSame(
            ['connectivity'],
            SupplierCapabilityTemplate::keysForModel('MOKO', 'MKGW3', 'gateway')
        );
        self::assertSame(
            ['low_battery', 'connectivity', 'battery', 'location'],
            SupplierCapabilityTemplate::keysForModel('MOKO', 'MKGW4', 'gateway')
        );
    }

    public function testNcsCatalogPlacesHelpCallInAlarms(): void
    {
        $definitions = CapabilityCatalog::definitionsForDeviceType('ncs');
        $match = array_values(array_filter(
            $definitions,
            static fn(array $definition): bool => ($definition['key'] ?? '') === 'help_call'
        ));

        self::assertCount(1, $match);
        self::assertSame('alarms', $match[0]['section'] ?? null);
        self::assertSame('Chamada de ajuda', $match[0]['label'] ?? null);
        self::assertFalse($match[0]['isConfigurable'] ?? true);
        self::assertFalse($match[0]['isTelemetry'] ?? true);
        self::assertTrue($match[0]['isEvent'] ?? false);
    }
}
