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
            'auto_vitals_interval',
            'blood_pressure',
            'call_whitelist',
            'device_password',
            'fall_detection',
            'fall_sensitivity',
            'heart_rate',
            'language_timezone',
            'location',
            'location_reporting_interval',
            'low_battery_alert',
            'monitor_number',
            'pedometer_schedule',
            'remove_watch_alarm',
            'remove_watch_sms_alert',
            'sleep_monitoring',
            'sos_contacts',
            'sos_sms_alert',
            'temperature',
            'temperature_measurement_interval',
        ];
        $actual = SupplierCapabilityTemplate::keysForSupplierDeviceType('4P Touch', 'watch');
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
        self::assertNotContains('push_message', $actual);
    }
}
