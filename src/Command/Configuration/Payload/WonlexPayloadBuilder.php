<?php

namespace Hub\Command\Configuration\Payload;

final class WonlexPayloadBuilder extends ConfigurationPayloadBuilder
{
    public static function build(string $key, array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return match ($key) {
            'locationInterval' => ['intervalTime' => self::nonNegativeInt($payload['intervalTime'] ?? null, 'intervalTime')],
            'deviceMeasuringFrequency', 'deviceConfig' => ['configs' => self::arrayField($payload['configs'] ?? null, 'configs')],
            'wonlexHeartRateInterval' => self::measurementInterval('upHeartRate', $payload),
            'wonlexBPInterval' => self::measurementInterval('upBP', $payload),
            'wonlexBOInterval' => self::measurementInterval('upBO', $payload),
            'wonlexBodyTemperatureInterval' => self::measurementInterval('upBodyTemperature', $payload),
            'wonlexStepInterval' => self::measurementInterval('upStep', $payload),
            'wonlexBreatheInterval' => self::measurementInterval('upBreathe', $payload),
            'wonlexECGInterval' => self::measurementInterval('upECG', $payload),
            'wonlexHRVInterval' => self::measurementInterval('upHRV', $payload),
            'wonlexPPGInterval' => self::measurementInterval('upPPG', $payload),
            'wonlexRRInterval' => self::measurementInterval('upRR', $payload),
            'wonlexContinuousBOCheck' => self::deviceToggle('ContinuousBOCheck', $payload),
            'wonlexContinuousHRSwitch' => self::deviceToggle('ContinuousHRSwitch', $payload),
            'wonlexPPGBPTrend' => self::deviceToggle('PPGBPTrend', $payload),
            'wonlexContinuousTempSwitch' => self::deviceToggle('ContinuousTempSwitch', $payload),
            'wonlexFallWarnSwitch' => self::deviceToggle('FallWarnSwitch', $payload),
            'wonlexSOSSwitch' => self::deviceToggle('SOSSwitch', $payload),
            'wonlexCallInLimitSwitch' => self::deviceToggle('CallInLimitSwitch', $payload),
            'wonlexStepTarget' => self::deviceNumber('StepTarget', 'steps', $payload),
            'wonlexLowPower' => self::deviceNumber('LowPower', 'Battery', $payload),
            'wonlexSleepIntervalOrSwitch' => self::sleepSettings($payload),
            'wonlexBloodOxygenWarn' => self::reminderThreshold('bloodOxygenWarn', $payload),
            'wonlexTemperatureExceedRemind' => self::reminderThreshold('TemperatureExceedRemind', $payload),
            'wonlexTemperatureBelowRemind' => self::reminderThreshold('TemperatureBelowRemind', $payload),
            'wonlexHeartRateHighRemind' => self::heartRateRange('HROvertopRemind', $payload),
            'wonlexHeartRateLowRemind' => self::heartRateRange('HeartRateBelowRemind', $payload),
            'wonlexBPEarlyWarning' => self::bloodPressureWarning($payload),
            'alarmClock' => ['alarmClock' => self::arrayField($payload['alarmClock'] ?? $payload['alarms'] ?? null, 'alarmClock')],
            'SOSNumber' => ['SOSNumber' => self::stringList($payload['numbers'] ?? [], 3, 'numbers')],
            'dnMedicationPlan' => ['plans' => self::arrayField($payload['plans'] ?? $payload['medicationPlan'] ?? null, 'plans')],
            'dnDevBindStatus' => ['bindStatus' => self::boolInt($payload['bindStatus'] ?? $payload['enabled'] ?? null, 'bindStatus')],
            default => throw new \InvalidArgumentException("Unsupported Wonlex configuration {$key}"),
        };
    }

    private static function measurementInterval(string $metric, array $payload): array
    {
        return ['configs' => [$metric => ['interval' => (string)self::positiveInt($payload['interval'] ?? null, 'interval')]]];
    }

    private static function deviceToggle(string $configName, array $payload): array
    {
        return ['configs' => [$configName => ['switchState' => self::boolInt($payload['switchState'] ?? $payload['enabled'] ?? null, 'switchState')]]];
    }

    private static function deviceNumber(string $configName, string $field, array $payload): array
    {
        return ['configs' => [$configName => [$field => self::nonNegativeInt($payload[$field] ?? $payload['value'] ?? null, $field)]]];
    }

    private static function sleepSettings(array $payload): array
    {
        return ['configs' => ['SleepIntervalOrSwitch' => [
            'switchState' => self::boolInt($payload['switchState'] ?? null, 'switchState'),
            'sleepStartTime' => self::requiredString($payload['sleepStartTime'] ?? null, 'sleepStartTime'),
            'sleepEndTime' => self::requiredString($payload['sleepEndTime'] ?? null, 'sleepEndTime'),
            'sleepTarget' => self::nonNegativeInt($payload['sleepTarget'] ?? null, 'sleepTarget'),
        ]]];
    }

    private static function reminderThreshold(string $configName, array $payload): array
    {
        $valueKey = array_key_exists('RemindValue', $payload) ? 'RemindValue' : 'reminderValue';

        return ['configs' => [$configName => [
            'switchState' => self::boolInt($payload['switchState'] ?? null, 'switchState'),
            $valueKey => self::nonNegativeInt($payload[$valueKey] ?? null, $valueKey),
        ]]];
    }

    private static function heartRateRange(string $configName, array $payload): array
    {
        return ['configs' => [$configName => [
            'switchState' => self::boolInt($payload['switchState'] ?? null, 'switchState'),
            'remindValue' => self::nonNegativeInt($payload['remindValue'] ?? null, 'remindValue'),
            'exerciseSwitchState' => self::boolInt($payload['exerciseSwitchState'] ?? null, 'exerciseSwitchState'),
            'exerciseHRMin' => self::nonNegativeInt($payload['exerciseHRMin'] ?? null, 'exerciseHRMin'),
            'exerciseHRMax' => self::nonNegativeInt($payload['exerciseHRMax'] ?? null, 'exerciseHRMax'),
            'exerciseRemindValue' => self::nonNegativeInt($payload['exerciseRemindValue'] ?? null, 'exerciseRemindValue'),
        ]]];
    }

    private static function bloodPressureWarning(array $payload): array
    {
        return ['configs' => ['BPEarlyWarning' => [
            'switchState' => self::boolInt($payload['switchState'] ?? null, 'switchState'),
            'hpWarn' => self::nonNegativeInt($payload['hpWarn'] ?? null, 'hpWarn'),
            'LPWarn' => self::nonNegativeInt($payload['LPWarn'] ?? null, 'LPWarn'),
        ]]];
    }
}
