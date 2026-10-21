<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

final class WonlexConfigurationDefinitions
{
    private const MEASUREMENT_INTERVALS = [
        'wonlexHeartRateInterval',
        'wonlexBPInterval',
        'wonlexBOInterval',
        'wonlexBodyTemperatureInterval',
        'wonlexStepInterval',
        'wonlexBreatheInterval',
        'wonlexECGInterval',
        'wonlexHRVInterval',
        'wonlexPPGInterval',
        'wonlexRRInterval',
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
        foreach (self::MEASUREMENT_INTERVALS as $index => $key) {
            $intervals[] = $entry($key, 'deviceMeasuringFrequency', 'number', ['intervalMinutes'], ['deviceMeasuringFrequency'], category: 'measurements', order: ($index + 1) * 10, bounds: ['intervalMinutes' => ['min' => 0]]);
        }

        return [
            $entry('locationInterval', 'locationInterval', 'number', ['intervalSeconds'], ['locationInterval'], category: 'intervals', order: 10, bounds: ['intervalSeconds' => ['min' => 0]]),
            ...$intervals,
            $device('wonlexStepTarget', 'number', ['steps'], 'health', 10, bounds: ['steps' => ['min' => 0]]),
            $device('wonlexContinuousBOCheck', 'toggle', ['enabled'], 'health', 20),
            $device('wonlexContinuousHRSwitch', 'toggle', ['enabled'], 'health', 30),
            $device('wonlexPPGBPTrend', 'toggle', ['enabled'], 'health', 40),
            $device('wonlexContinuousTempSwitch', 'toggle', ['enabled'], 'health', 50),
            $device('wonlexSleepIntervalOrSwitch', 'wonlexSleepSettings', ['enabled', 'startTime', 'endTime', 'targetMinutes'], 'health', 60, bounds: ['targetMinutes' => ['min' => 0]]),
            $device('wonlexBloodOxygenWarn', 'wonlexReminderThreshold', ['enabled', 'thresholdPercent'], 'alerts', 40, bounds: ['thresholdPercent' => ['min' => 0]]),
            $device('wonlexTemperatureExceedRemind', 'wonlexReminderThreshold', ['enabled', 'thresholdCelsius'], 'alerts', 50, bounds: ['thresholdCelsius' => ['min' => 0]]),
            $device('wonlexTemperatureBelowRemind', 'wonlexReminderThreshold', ['enabled', 'thresholdCelsius'], 'alerts', 60, bounds: ['thresholdCelsius' => ['min' => 0]]),
            $device('wonlexBPEarlyWarning', 'wonlexBloodPressureWarning', ['enabled', 'systolicMmHg', 'diastolicMmHg'], 'alerts', 70, bounds: ['systolicMmHg' => ['min' => 0], 'diastolicMmHg' => ['min' => 0]]),
            $device('wonlexHeartRateHighRemind', 'wonlexHeartRateRange', $heartRate, 'alerts', 80, bounds: $heartRateBounds),
            // O limite principal fica por preencher: a ficha só dá o exemplo do alerta alto.
            $device('wonlexHeartRateLowRemind', 'wonlexHeartRateRange', $heartRate, 'alerts', 90, bounds: $heartRateBounds, options: ['default' => [
                'enabled' => true, 'exerciseEnabled' => true, 'exerciseMinBpm' => 100, 'exerciseMaxBpm' => 140, 'exerciseThresholdBpm' => 140,
            ]]),
            $device('wonlexLowPower', 'number', ['thresholdPercent'], 'alerts', 10, bounds: ['thresholdPercent' => ['min' => 0]]),
            $device('wonlexFallWarnSwitch', 'toggle', ['enabled'], 'alerts', 20),
            $device('wonlexSOSSwitch', 'toggle', ['enabled'], 'alerts', 30),
            $device('wonlexCallInLimitSwitch', 'toggle', ['enabled'], 'contacts', 35),
            $entry('alarmClock', 'alarmClock', 'alarm_clock', ['alarmClockList'], ['alarmClock'], category: 'alerts', order: 10, limit: 10),
            $entry('familyNumber', 'familyNumber', 'phonebook', ['contacts'], ['familyNumber'], category: 'contacts', order: 5, limit: 10),
            $entry('SOSNumber', 'SOSNumber', 'sos_contacts', ['numbers'], ['SOSNumber'], category: 'contacts', order: 10, limit: 10),
            $entry('factoryResetCommand', 'reset', 'action', [], ['reset'], category: 'system', order: 110, transient: true, destructive: true, verb: 'factory_reset'),
            $entry('restartCommand', 'restart', 'action', [], ['restart'], category: 'system', order: 120, transient: true, destructive: true),
            $entry('powerOffCommand', 'powerOff', 'action', [], ['powerOff'], category: 'system', order: 130, transient: true, destructive: true),
            $entry('findDeviceCommand', 'find', 'action', [], ['find'], category: 'system', order: 140, transient: true),
            // O `msgNotice` é notificação de sentido único: a Wonlex não lhe define resposta.
            $entry('pushMessage', 'msgNotice', 'pushMessage', ['message'], category: 'system', order: 145, transient: true, verb: 'send_message'),
        ];
    }
}
