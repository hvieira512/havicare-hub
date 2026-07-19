<?php

namespace Hub\Domain;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\Definition\NcsCapabilityDefinitions;
use Hub\Domain\Capability\Definition\RadarCapabilityDefinitions;
use Hub\Domain\Capability\Definition\WatchCapabilityDefinitions;

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
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function definitions(): array
    {
        return array_merge(
            WatchCapabilityDefinitions::all(),
            NcsCapabilityDefinitions::all(),
            RadarCapabilityDefinitions::all(),
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
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
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
        foreach (self::protocolSpecificKeys($protocol) as $protocolKey) {
            $keys[$protocolKey] = true;
        }
        foreach (self::telemetryKeysForProtocol($protocol) as $telemetryKey) {
            $keys[$telemetryKey] = true;
        }

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
     * @return list<string>
     */
    public static function protocolSpecificKeys(string $protocol): array
    {
        return match ($protocol) {
            'voerka-ncs' => ['pager_call'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function telemetryKeysForProtocol(string $protocol): array
    {
        return match ($protocol) {
            'wonlex-json' => [
                'battery',
                'activity',
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
                'blood_sugar',
            ],
            'vivistar-iw' => [
                'battery',
                'activity',
                'heart_rate',
                'blood_pressure',
                'blood_oxygen',
                'temperature',
                'location',
                'blood_sugar',
            ],
            'four-p-touch' => [
                'battery',
                'activity',
                'heart_rate',
                'blood_pressure',
                'blood_oxygen',
                'temperature',
                'location',
            ],
            'qinglanst-radar' => [
                'positions',
                'vitals',
                'position_minute_stats',
                'vitals_minute_stats',
            ],
            default => [],
        };
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

        $catalog = [];
        foreach (self::definitions() as $definition) {
            $definitionKey = trim((string)($definition['key'] ?? ''));
            if ($definitionKey !== '') {
                $catalog[$definitionKey] = true;
            }
        }
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
            'blood_sugar',
            'firmware_version',
            'device_status' => $feature,
            default => null,
        };
    }

    public static function mapConfigurationKey(string $key): ?string
    {
        $key = trim($key);

        return match ($key) {
            'alarm_clock' => 'alarm_clock',
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
            'wonlexCallInLimitSwitch', 'callInRestriction' => 'call_in_restriction',
            'deviceConfig' => 'device_settings_sync',
            'devicePassword' => 'device_password',
            'languageTimezone' => 'language_timezone',
            'soundProfile' => 'sound_profile',
            'doNotDisturb' => 'do_not_disturb',
            default => null,
        };
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
