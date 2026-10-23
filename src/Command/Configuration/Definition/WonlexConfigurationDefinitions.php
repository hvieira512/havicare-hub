<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

final class WonlexConfigurationDefinitions
{
    private const MEASUREMENT_INTERVALS = [
        'wonlexHeartRateInterval' => 'heart_rate_measurement_interval',
        'wonlexBPInterval' => 'blood_pressure_measurement_interval',
        'wonlexBOInterval' => 'blood_oxygen_measurement_interval',
        'wonlexBodyTemperatureInterval' => 'temperature_measurement_interval',
        'wonlexStepInterval' => 'step_reporting_interval',
        'wonlexBreatheInterval' => 'breath_rate_measurement_interval',
        'wonlexECGInterval' => 'ecg_measurement_interval',
        'wonlexHRVInterval' => 'hrv_measurement_interval',
        'wonlexPPGInterval' => 'ppg_measurement_interval',
        'wonlexRRInterval' => 'rr_interval_measurement_interval',
    ];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);
        $device = static fn(string $key, string $input, array $fields, string $category, int $order, mixed ...$named): array
            => ConfigurationDefinition::make($key, 'deviceConfig', $input, $fields, ['deviceConfig'], $category, $order, ...$named);
        $heartRate = ['enabled', 'thresholdBpm', 'exerciseEnabled', 'exerciseMinBpm', 'exerciseMaxBpm', 'exerciseThresholdBpm'];
        $heartRateBounds = ['thresholdBpm' => ['min' => 0], 'exerciseMinBpm' => ['min' => 0], 'exerciseMaxBpm' => ['min' => 0], 'exerciseThresholdBpm' => ['min' => 0]];

        $intervals = [];
        $order = 10;
        foreach (self::MEASUREMENT_INTERVALS as $key => $capability) {
            $intervals[] = $entry($key, 'deviceMeasuringFrequency', 'number', ['intervalMinutes'], ['deviceMeasuringFrequency'], category: 'measurements', order: $order, bounds: ['intervalMinutes' => ['min' => 0]], capability: $capability);
            $order += 10;
        }

        return [
            $entry('locationInterval', 'locationInterval', 'number', ['intervalSeconds'], ['locationInterval'], category: 'intervals', order: 10, bounds: ['intervalSeconds' => ['min' => 0]], capability: 'location_reporting_interval'),
            ...$intervals,
            $device('wonlexStepTarget', 'number', ['steps'], 'health', 10, bounds: ['steps' => ['min' => 0]], capability: 'step_goal'),
            $device('wonlexContinuousBOCheck', 'toggle', ['enabled'], 'health', 20, capability: 'blood_oxygen_continuous'),
            $device('wonlexContinuousHRSwitch', 'toggle', ['enabled'], 'health', 30, capability: 'heart_rate_continuous'),
            $device('wonlexPPGBPTrend', 'toggle', ['enabled'], 'health', 40, capability: 'blood_pressure_trend'),
            $device('wonlexContinuousTempSwitch', 'toggle', ['enabled'], 'health', 50, capability: 'temperature_continuous'),
            $device('wonlexSleepIntervalOrSwitch', 'wonlexSleepSettings', ['enabled', 'startTime', 'endTime', 'targetMinutes'], 'health', 60, bounds: ['targetMinutes' => ['min' => 0]], capability: 'sleep_monitoring'),
            $device('wonlexBloodOxygenWarn', 'wonlexReminderThreshold', ['enabled', 'thresholdPercent'], 'alerts', 40, bounds: ['thresholdPercent' => ['min' => 0]], capability: 'blood_oxygen_alert'),
            $device('wonlexTemperatureExceedRemind', 'wonlexReminderThreshold', ['enabled', 'thresholdCelsius'], 'alerts', 50, bounds: ['thresholdCelsius' => ['min' => 0]], capability: 'temperature_high_alert'),
            $device('wonlexTemperatureBelowRemind', 'wonlexReminderThreshold', ['enabled', 'thresholdCelsius'], 'alerts', 60, bounds: ['thresholdCelsius' => ['min' => 0]], capability: 'temperature_low_alert'),
            $device('wonlexBPEarlyWarning', 'wonlexBloodPressureWarning', ['enabled', 'systolicMmHg', 'diastolicMmHg'], 'alerts', 70, bounds: ['systolicMmHg' => ['min' => 0], 'diastolicMmHg' => ['min' => 0]], capability: 'blood_pressure_alert'),
            $device('wonlexHeartRateHighRemind', 'wonlexHeartRateRange', $heartRate, 'alerts', 80, bounds: $heartRateBounds, capability: 'heart_rate_high_alert'),
            // Sem limite por omissão: o exemplo da ficha para o alerta baixo é cópia do alto, 120.
            $device('wonlexHeartRateLowRemind', 'wonlexHeartRateRange', $heartRate, 'alerts', 90, bounds: $heartRateBounds, options: ['default' => [
                'enabled' => true, 'exerciseEnabled' => true, 'exerciseMinBpm' => 100, 'exerciseMaxBpm' => 140, 'exerciseThresholdBpm' => 140,
            ]], capability: 'heart_rate_low_alert'),
            $device('wonlexLowPower', 'number', ['thresholdPercent'], 'alerts', 10, bounds: ['thresholdPercent' => ['min' => 0]], capability: 'low_battery_alert'),
            $device('wonlexFallWarnSwitch', 'toggle', ['enabled'], 'alerts', 20, capability: 'fall_detection'),
            $device('wonlexSOSSwitch', 'toggle', ['enabled'], 'alerts', 30, capability: 'sos_sms_alert'),
            $device('wonlexCallInLimitSwitch', 'toggle', ['enabled'], 'contacts', 35, capability: 'whitelist_enabled'),
            $entry('alarmClock', 'alarmClock', 'alarm_clock', ['alarmClockList'], ['alarmClock'], category: 'alerts', order: 10, limit: 10, capability: 'alarm_clock'),
            $entry('familyNumber', 'familyNumber', 'phonebook', ['contacts'], ['familyNumber'], category: 'contacts', order: 5, limit: 10, capability: 'phonebook'),
            $entry('SOSNumber', 'SOSNumber', 'sos_contacts', ['numbers'], ['SOSNumber'], category: 'contacts', order: 10, limit: 10, capability: 'sos_contacts'),
            $entry('factoryResetCommand', 'reset', 'action', [], ['reset'], category: 'system', order: 110, transient: true, destructive: true, verb: 'factory_reset', capability: 'reset_device'),
            $entry('restartCommand', 'restart', 'action', [], ['restart'], category: 'system', order: 120, transient: true, destructive: true, capability: 'restart_device'),
            $entry('powerOffCommand', 'powerOff', 'action', [], ['powerOff'], category: 'system', order: 130, transient: true, destructive: true, capability: 'power_off'),
            $entry('findDeviceCommand', 'find', 'action', [], ['find'], category: 'system', order: 140, transient: true, capability: 'find_device'),
        ];
    }
}
