<?php

namespace Hub\Command\Configuration\Payload;

final class WonlexPayloadBuilder extends ConfigurationPayloadBuilder
{
    private const ALARM_CLOCK_LIMIT = 10;

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
            'alarmClock' => ['alarmClockList' => self::alarmClockList($payload)],
            'familyNumber' => ['familyNumbers' => self::familyNumbers($payload['contacts'] ?? $payload['familyNumbers'] ?? null)],
            'SOSNumber' => ['sosNumbers' => self::sosNumbers($payload['contacts'] ?? $payload['numbers'] ?? $payload['sosNumbers'] ?? [])],
            'dnMedicationPlan' => self::medicationPlan($payload),
            'dnDevBindStatus' => ['status' => self::boolInt($payload['status'] ?? $payload['bindStatus'] ?? $payload['enabled'] ?? null, 'status')],
            'resetCommand', 'restartCommand', 'powerOffCommand', 'findDeviceCommand' => [],
            'pushMessage' => [
                'msgType' => 'msg',
                'msg' => self::requiredString($payload['message'] ?? null, 'message'),
            ],
            default => throw new \InvalidArgumentException("Unsupported Wonlex configuration {$key}"),
        };
    }

    private static function measurementInterval(string $metric, array $payload): array
    {
        return ['configs' => [$metric => ['interval' => (string)self::nonNegativeInt($payload['interval'] ?? null, 'interval')]]];
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
            $valueKey => self::nonNegativeFloat($payload[$valueKey] ?? null, $valueKey),
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

    private static function alarmClockList(array $payload): array
    {
        $items = $payload['alarmClockList'] ?? $payload['alarmClock'] ?? $payload['alarms'] ?? $payload['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \InvalidArgumentException('alarmClockList must be an array');
        }
        if (count($items) > self::ALARM_CLOCK_LIMIT) {
            throw new \InvalidArgumentException('alarmClockList must contain at most 10 items');
        }

        return array_values(array_map(static function (mixed $item): array {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('alarmClockList items must be objects');
            }
            $label = trim((string)($item['label'] ?? ''));
            $time = trim((string)($item['startTime'] ?? $item['time'] ?? ''));
            if ($time === '') {
                throw new \InvalidArgumentException('alarm startTime is required');
            }
            if (preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time) !== 1) {
                throw new \InvalidArgumentException('alarm startTime must use 24-hour HH:mm format');
            }
            $week = $item['week'] ?? null;
            if ($week === null && is_array($item['recurrence'] ?? null)) {
                $kind = strtolower(trim((string)($item['recurrence']['kind'] ?? '')));
                if ($kind === 'daily') {
                    $week = '1111111';
                } elseif ($kind === 'once') {
                    throw new \InvalidArgumentException('recurrence once is not supported by Wonlex alarmClock');
                } elseif ($kind === 'custom') {
                    $days = $item['recurrence']['days'] ?? null;
                    if (!is_array($days) || !array_is_list($days) || $days === []) {
                        throw new \InvalidArgumentException('custom recurrence requires at least one day');
                    }
                    $enabledDays = [];
                    foreach ($days as $day) {
                        if (!(is_int($day) || (is_string($day) && ctype_digit($day)))) {
                            throw new \InvalidArgumentException('custom recurrence days must be integers from 1 to 7');
                        }
                        $day = (int)$day;
                        if ($day < 1 || $day > 7) {
                            throw new \InvalidArgumentException('custom recurrence days must be integers from 1 to 7');
                        }
                        $enabledDays[$day] = true;
                    }
                    $week = implode('', array_map(
                        static fn(int $day): string => isset($enabledDays[$day]) ? '1' : '0',
                        range(1, 7)
                    ));
                } else {
                    throw new \InvalidArgumentException('alarm recurrence must be daily or custom');
                }
            }
            if ($week === null) {
                throw new \InvalidArgumentException('alarm week or recurrence is required');
            }
            $week = trim((string)$week);
            if (preg_match('/^[01]{7}$/', $week) !== 1) {
                throw new \InvalidArgumentException('alarm week must contain exactly 7 zero-or-one characters');
            }

            $url = trim((string)($item['url'] ?? ''));
            if ($url !== '' && (
                filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            )) {
                throw new \InvalidArgumentException('alarm url must be a valid HTTP or HTTPS URL');
            }

            return array_filter([
                'label' => $label,
                'startTime' => $time,
                'week' => $week,
                'status' => (string)self::boolInt($item['status'] ?? $item['enabled'] ?? true, 'status'),
                'url' => $url,
            ], static fn (mixed $value): bool => $value !== '');
        }, array_values($items)));
    }

    private static function familyNumbers(mixed $contacts): array
    {
        if (!is_array($contacts) || !array_is_list($contacts)) {
            throw new \InvalidArgumentException('contacts must be an array');
        }
        if (count($contacts) > 10) {
            throw new \InvalidArgumentException('contacts must contain at most 10 values');
        }

        return array_values(array_map(static function (mixed $contact): array {
            if (!is_array($contact)) {
                throw new \InvalidArgumentException('contacts items must be objects');
            }
            $phone = self::requiredString($contact['phone'] ?? null, 'phone');
            return [
                'familyNumberId' => trim((string)($contact['familyNumberId'] ?? substr(sha1($phone), 0, 8))),
                'name' => trim((string)($contact['name'] ?? '')),
                'phone' => $phone,
                'sosSwitch' => self::boolInt($contact['sosSwitch'] ?? false, 'sosSwitch'),
                'areaCode' => trim((string)($contact['areaCode'] ?? '')),
            ];
        }, $contacts));
    }

    private static function sosNumbers(mixed $contacts): array
    {
        if (!is_array($contacts) || !array_is_list($contacts)) {
            throw new \InvalidArgumentException('numbers must be an array');
        }
        if (count($contacts) > 10) {
            throw new \InvalidArgumentException('numbers must contain at most 10 values');
        }

        return array_values(array_map(static function (mixed $contact): array {
            $contact = is_array($contact) ? $contact : ['phone' => $contact];
            $phone = self::requiredString($contact['phone'] ?? null, 'phone');
            return [
                'sosNumberId' => trim((string)($contact['sosNumberId'] ?? $contact['familyNumberId'] ?? substr(sha1($phone), 0, 8))),
                'name' => trim((string)($contact['name'] ?? '')),
                'phone' => $phone,
            ];
        }, $contacts));
    }

    private static function medicationPlan(array $payload): array
    {
        if (array_key_exists('plans', $payload) && $payload['plans'] === []) {
            return ['plans' => []];
        }

        $plan = $payload['plan'] ?? $payload['medicationPlan'] ?? null;
        if ($plan === null && isset($payload['plans'])) {
            if (!is_array($payload['plans']) || count($payload['plans']) !== 1 || !is_array($payload['plans'][0])) {
                throw new \InvalidArgumentException('Wonlex sends one medication plan per command');
            }
            $plan = $payload['plans'][0];
        }
        if ($plan === null) {
            $plan = $payload;
        }
        if (!is_array($plan)) {
            throw new \InvalidArgumentException('plan must be an object');
        }

        foreach (['drugType', 'drugName', 'drugStartTime', 'drugEndTime', 'drugInterval', 'drugTime'] as $field) {
            if (!array_key_exists($field, $plan)) {
                throw new \InvalidArgumentException("{$field} is required");
            }
        }

        return [
            'drugType' => self::nonNegativeInt($plan['drugType'], 'drugType'),
            'drugName' => self::requiredString($plan['drugName'], 'drugName'),
            'drugDose' => self::nonNegativeFloat($plan['drugDose'] ?? 0, 'drugDose'),
            'drugUnit' => (string)($plan['drugUnit'] ?? '5'),
            'drugStartTime' => self::requiredString($plan['drugStartTime'], 'drugStartTime'),
            'drugEndTime' => self::requiredString($plan['drugEndTime'], 'drugEndTime'),
            'drugInterval' => self::nonNegativeFloat($plan['drugInterval'], 'drugInterval'),
            'drugTime' => self::arrayField($plan['drugTime'], 'drugTime'),
        ];
    }

}
