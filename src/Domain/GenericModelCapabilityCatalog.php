<?php

namespace Hub\Domain;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;

final class GenericModelCapabilityCatalog
{
    /**
     * @return list<string>
     */
    public static function deviceTypes(): array
    {
        return ['watch', 'ncs', 'radar'];
    }

    /**
     * @return array<string, string>
     */
    public static function sections(): array
    {
        return [
            'telemetry' => 'Telemetry',
            'health' => 'Health',
            'contacts' => 'Contacts',
            'alarms' => 'Alarms',
            'settings_system' => 'Settings / System',
        ];
    }

    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::watchDefinitions(),
            self::deviceTypePlaceholderDefinitions('ncs'),
            self::deviceTypePlaceholderDefinitions('radar'),
        );
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return self::keysForDeviceType('watch');
    }

    /**
     * @return list<string>
     */
    public static function keysForDeviceType(string $deviceType): array
    {
        $keys = [];
        foreach (self::definitionsForDeviceType($deviceType) as $definition) {
            $keys[$definition['key']] = true;
        }

        return array_keys($keys);
    }

    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool}>
     */
    public static function definitionsForDeviceType(string $deviceType): array
    {
        $normalized = DeviceMetadata::normalizeDeviceType($deviceType);

        return array_values(array_filter(
            self::definitions(),
            static fn(array $definition): bool => ($definition['deviceType'] ?? 'watch') === $normalized
        ));
    }

    /**
     * @return list<string>
     */
    public static function keysForProtocol(string $protocol): array
    {
        $keys = [];
        foreach (DeviceCommandCatalog::featuresForProtocol($protocol) as $feature) {
            $generic = self::mapTelemetryFeature($feature);
            if ($generic !== null) {
                $keys[$generic] = true;
            }
        }

        foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $config) {
            $generic = self::mapConfigurationKey((string)($config['key'] ?? ''));
            if ($generic !== null) {
                $keys[$generic] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param list<array{section?: string, capability_key?: string, key?: string}> $catalogRows
     * @param list<string> $supportedKeys
     * @return array<string, array<string, bool>>
     */
    public static function buildCapabilityMatrix(array $catalogRows, array $supportedKeys): array
    {
        $supported = array_fill_keys(self::normalizeKeys($supportedKeys), true);
        $matrix = [];
        foreach (self::sections() as $section => $_label) {
            $matrix[$section] = [];
        }

        foreach ($catalogRows as $row) {
            $section = trim((string)($row['section'] ?? ''));
            $key = trim((string)($row['capability_key'] ?? $row['key'] ?? ''));
            if ($section === '' || $key === '' || !isset($matrix[$section])) {
                continue;
            }
            $matrix[$section][$key] = isset($supported[$key]);
        }

        return $matrix;
    }

    public static function normalizeStoredCapabilityKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        $catalog = array_flip(self::keys());
        if (isset($catalog[$key])) {
            return $key;
        }

        return self::mapTelemetryFeature($key) ?? self::mapConfigurationKey($key);
    }

    public static function sectionForCapabilityKey(string $key): ?string
    {
        foreach (self::definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition['section'];
            }
        }

        return null;
    }

    public static function mapTelemetryFeature(string $feature): ?string
    {
        $feature = trim($feature);

        return match ($feature) {
            'heart_rate',
            'blood_pressure',
            'blood_oxygen',
            'temperature',
            'breath_rate',
            'location',
            'sleep',
            'ecg',
            'hrv',
            'ppg',
            'rr_interval',
            'battery',
            'activity',
            'heartbeat',
            'blood_sugar' => $feature,
            default => null,
        };
    }

    public static function mapConfigurationKey(string $key): ?string
    {
        $key = trim($key);

        return match ($key) {
            'alarmClock', 'reminders' => 'alarm_clock',
            'takePills' => 'medication_reminders',
            'dnMedicationPlan' => 'medication_reminders',
            'wonlexLowPower', 'lowBatterySmsAlerts' => 'low_battery_alert',
            'wonlexFallWarnSwitch', 'fallDetection', 'fallDownAlert' => 'fall_detection',
            'fallSensitivity', 'fallDownSensitivity' => 'fall_sensitivity',
            'wonlexSOSSwitch', 'sosSmsAlerts' => 'sos_sms_alert',
            'wonlexBloodOxygenWarn' => 'blood_oxygen_alert',
            'wonlexTemperatureExceedRemind' => 'temperature_high_alert',
            'wonlexTemperatureBelowRemind' => 'temperature_low_alert',
            'wonlexBPEarlyWarning' => 'blood_pressure_alert',
            'wonlexHeartRateHighRemind' => 'heart_rate_high_alert',
            'wonlexHeartRateLowRemind' => 'heart_rate_low_alert',
            'removeWatchAlarm' => 'remove_watch_alarm',
            'removeWatchSmsAlerts' => 'remove_watch_sms_alert',
            'SOSNumber', 'sosContacts', 'sosNumber1', 'sosNumber2', 'sosNumber3' => 'sos_contacts',
            'phonebook' => 'phonebook',
            'pushMessage' => 'push_message',
            'makeCall' => 'make_call',
            'centerNumber' => 'center_number',
            'resetCommand' => 'reset_device',
            'powerOffCommand' => 'power_off',
            'findDeviceCommand' => 'find_device',
            'whitelistSwitch', 'whitelistGroup1', 'whitelistGroup2' => 'call_whitelist',
            'monitorNumber' => 'monitor_number',
            'autoHealthMeasurement', 'healthAutoMeasurement' => 'auto_vitals_interval',
            'wonlexHeartRateInterval' => 'heart_rate_measurement_interval',
            'wonlexBPInterval' => 'blood_pressure_measurement_interval',
            'wonlexBOInterval' => 'blood_oxygen_measurement_interval',
            'wonlexBodyTemperatureInterval', 'bodyTemperatureInterval' => 'temperature_measurement_interval',
            'wonlexBreatheInterval' => 'breath_rate_measurement_interval',
            'wonlexECGInterval' => 'ecg_measurement_interval',
            'wonlexHRVInterval' => 'hrv_measurement_interval',
            'wonlexPPGInterval' => 'ppg_measurement_interval',
            'wonlexRRInterval' => 'rr_interval_measurement_interval',
            'wonlexContinuousHRSwitch' => 'heart_rate_continuous',
            'wonlexContinuousBOCheck' => 'blood_oxygen_continuous',
            'wonlexPPGBPTrend' => 'blood_pressure_trend',
            'wonlexContinuousTempSwitch' => 'temperature_continuous',
            'wonlexStepTarget' => 'step_goal',
            'wonlexSleepIntervalOrSwitch', 'sleepTime' => 'sleep_monitoring',
            'bloodPressureCalibration' => 'blood_pressure_calibration',
            'wonlexStepInterval' => 'step_reporting_interval',
            'walkTime' => 'pedometer_schedule',
            'locationInterval', 'uploadInterval' => 'location_reporting_interval',
            'workingMode' => 'working_mode',
            'dnDevBindStatus' => 'device_binding',
            'wonlexCallInLimitSwitch' => 'call_in_restriction',
            'deviceConfig' => 'device_settings_sync',
            'devicePassword' => 'device_password',
            'languageTimezone' => 'language_timezone',
            'soundProfile' => 'sound_profile',
            default => null,
        };
    }

    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool}>
     */
    private static function watchDefinitions(): array
    {
        return [
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Battery', 'sortOrder' => 5, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'activity', 'label' => 'Activity (steps)', 'sortOrder' => 6, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'heart_rate', 'label' => 'Heart rate', 'sortOrder' => 10, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'blood_pressure', 'label' => 'Blood pressure', 'sortOrder' => 20, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'blood_oxygen', 'label' => 'Blood oxygen', 'sortOrder' => 30, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'temperature', 'label' => 'Body temperature', 'sortOrder' => 40, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'breath_rate', 'label' => 'Breath rate', 'sortOrder' => 50, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'location', 'label' => 'Location', 'sortOrder' => 60, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'sleep', 'label' => 'Sleep', 'sortOrder' => 70, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'ecg', 'label' => 'ECG', 'sortOrder' => 80, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'hrv', 'label' => 'HRV', 'sortOrder' => 90, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'ppg', 'label' => 'PPG', 'sortOrder' => 100, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'rr_interval', 'label' => 'RR interval', 'sortOrder' => 110, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'auto_vitals_interval', 'label' => 'Auto vitals interval', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'heart_rate_measurement_interval', 'label' => 'Heart rate measurement interval', 'sortOrder' => 20, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_pressure_measurement_interval', 'label' => 'Blood pressure measurement interval', 'sortOrder' => 30, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_oxygen_measurement_interval', 'label' => 'Blood oxygen measurement interval', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'temperature_measurement_interval', 'label' => 'Temperature measurement interval', 'sortOrder' => 50, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'breath_rate_measurement_interval', 'label' => 'Breath rate measurement interval', 'sortOrder' => 60, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'ecg_measurement_interval', 'label' => 'ECG measurement interval', 'sortOrder' => 70, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'hrv_measurement_interval', 'label' => 'HRV measurement interval', 'sortOrder' => 80, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'ppg_measurement_interval', 'label' => 'PPG measurement interval', 'sortOrder' => 90, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'rr_interval_measurement_interval', 'label' => 'RR interval measurement interval', 'sortOrder' => 100, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'heart_rate_continuous', 'label' => 'Continuous heart rate', 'sortOrder' => 110, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_oxygen_continuous', 'label' => 'Continuous blood oxygen', 'sortOrder' => 120, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_pressure_trend', 'label' => 'Blood pressure trend', 'sortOrder' => 130, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'temperature_continuous', 'label' => 'Continuous temperature', 'sortOrder' => 140, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'step_goal', 'label' => 'Step goal', 'sortOrder' => 150, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'sleep_monitoring', 'label' => 'Sleep monitoring', 'sortOrder' => 160, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_pressure_calibration', 'label' => 'Blood pressure calibration', 'sortOrder' => 170, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'phonebook', 'label' => 'Phonebook', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'push_message', 'label' => 'Push message to watch', 'sortOrder' => 5, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'call_whitelist', 'label' => 'Call whitelist', 'sortOrder' => 30, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'monitor_number', 'label' => 'Monitor number', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'sos_contacts', 'label' => 'SOS contacts', 'sortOrder' => 50, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'alarm_clock', 'label' => 'Alarm clock', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'medication_reminders', 'label' => 'Medication reminders', 'sortOrder' => 20, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'low_battery_alert', 'label' => 'Low battery alert', 'sortOrder' => 30, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'fall_detection', 'label' => 'Fall detection', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'fall_sensitivity', 'label' => 'Fall sensitivity', 'sortOrder' => 50, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'sos_sms_alert', 'label' => 'SOS SMS alert', 'sortOrder' => 60, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'blood_oxygen_alert', 'label' => 'Blood oxygen alert', 'sortOrder' => 70, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'temperature_high_alert', 'label' => 'High temperature alert', 'sortOrder' => 80, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'temperature_low_alert', 'label' => 'Low temperature alert', 'sortOrder' => 90, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'blood_pressure_alert', 'label' => 'Blood pressure alert', 'sortOrder' => 100, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'heart_rate_high_alert', 'label' => 'High heart rate alert', 'sortOrder' => 110, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'heart_rate_low_alert', 'label' => 'Low heart rate alert', 'sortOrder' => 120, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'remove_watch_alarm', 'label' => 'Remove watch alarm', 'sortOrder' => 130, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'remove_watch_sms_alert', 'label' => 'Remove watch SMS alert', 'sortOrder' => 140, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'working_mode', 'label' => 'Working mode', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'device_binding', 'label' => 'Device binding', 'sortOrder' => 20, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'call_in_restriction', 'label' => 'Call restriction', 'sortOrder' => 30, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'device_settings_sync', 'label' => 'Device settings sync', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'device_password', 'label' => 'Device password', 'sortOrder' => 50, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'language_timezone', 'label' => 'Language and timezone', 'sortOrder' => 70, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'location_reporting_interval', 'label' => 'Location reporting interval', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'sound_profile', 'label' => 'Sound profile', 'sortOrder' => 11, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'make_call', 'label' => 'Make call', 'sortOrder' => 12, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'reset_device', 'label' => 'Reset device', 'sortOrder' => 14, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'power_off', 'label' => 'Power off', 'sortOrder' => 16, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'find_device', 'label' => 'Find device', 'sortOrder' => 18, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'center_number', 'label' => 'Center number', 'sortOrder' => 45, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'step_reporting_interval', 'label' => 'Step interval', 'sortOrder' => 180, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'pedometer_schedule', 'label' => 'Pedometer schedule', 'sortOrder' => 190, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
        ];
    }

    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool}>
     */
    private static function deviceTypePlaceholderDefinitions(string $deviceType): array
    {
        return [];
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function normalizeKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            $key = trim($key);
            if ($key !== '') {
                $normalized[$key] = true;
            }
        }

        return array_keys($normalized);
    }
}
