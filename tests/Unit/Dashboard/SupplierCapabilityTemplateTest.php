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
        $expected = [
            'alarm_clock',
            'auto_vitals_interval',
            'blood_oxygen',
            'blood_pressure',
            'blood_pressure_calibration',
            'call_whitelist',
            'fall_detection',
            'fall_sensitivity',
            'heart_rate',
            'location',
            'phonebook',
            'push_message',
            'sos_contacts',
            'temperature',
            'working_mode',
        ];
        $actual = SupplierCapabilityTemplate::keysForSupplierDeviceType('Vivistar', 'watch');
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
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
        $expected = [
            'alarm_clock',
            'auto_vitals_interval',
            'blood_pressure',
            'call_in_restriction',
            'call_whitelist',
            'center_number',
            'device_password',
            'device_status',
            'do_not_disturb',
            'fall_detection',
            'fall_sensitivity',
            'find_device',
            'firmware_version',
            'heart_rate',
            'language_timezone',
            'location',
            'location_reporting_interval',
            'low_battery_alert',
            'make_call',
            'monitor_number',
            'pedometer_schedule',
            'medication_reminders',
            'phonebook',
            'power_off',
            'push_message',
            'remove_watch_alarm',
            'remove_watch_sms_alert',
            'reset_device',
            'sleep_monitoring',
            'sos_contacts',
            'sos_sms_alert',
            'sound_profile',
            'temperature',
            'temperature_measurement_interval',
        ];
        $actual = SupplierCapabilityTemplate::keysForSupplierDeviceType('4P Touch', 'watch');
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }
}
