<?php

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\SupplierCapabilityTemplate;
use PHPUnit\Framework\TestCase;

final class SupplierCapabilityTemplateTest extends TestCase
{
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
    }
}
