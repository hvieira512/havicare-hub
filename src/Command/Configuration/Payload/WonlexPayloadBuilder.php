<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Payload;

final class WonlexPayloadBuilder extends ConfigurationPayloadBuilder
{
    private const ALARM_CLOCK_LIMIT = 10;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function build(string $key, array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return match ($key) {
            'locationInterval' => ['intervalTime' => self::nonNegativeInt($payload['intervalSeconds'] ?? null, 'intervalSeconds')],
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
            'wonlexStepTarget' => ['configs' => ['StepTarget' => ['steps' => self::nonNegativeInt($payload['steps'] ?? null, 'steps')]]],
            'wonlexLowPower' => ['configs' => ['LowPower' => ['Battery' => self::nonNegativeInt($payload['thresholdPercent'] ?? null, 'thresholdPercent')]]],
            'wonlexSleepIntervalOrSwitch' => self::sleepSettings($payload),
            'wonlexBloodOxygenWarn' => self::reminderThreshold('bloodOxygenWarn', 'reminderValue', 'thresholdPercent', $payload),
            'wonlexTemperatureExceedRemind' => self::reminderThreshold('TemperatureExceedRemind', 'RemindValue', 'thresholdCelsius', $payload),
            'wonlexTemperatureBelowRemind' => self::reminderThreshold('TemperatureBelowRemind', 'RemindValue', 'thresholdCelsius', $payload),
            'wonlexHeartRateHighRemind' => self::heartRateRange('HROvertopRemind', $payload),
            'wonlexHeartRateLowRemind' => self::heartRateRange('HeartRateBelowRemind', $payload),
            'wonlexBPEarlyWarning' => self::bloodPressureWarning($payload),
            'alarmClock' => ['alarmClockList' => self::alarmClockList($payload)],
            'familyNumber' => ['familyNumbers' => self::familyNumbers($payload['contacts'] ?? null)],
            'SOSNumber' => ['sosNumbers' => self::sosNumbers($payload['contacts'] ?? $payload['numbers'] ?? [])],
            'factoryResetCommand', 'restartCommand', 'powerOffCommand', 'findDeviceCommand' => [],
            'pushMessage' => [
                'msgType' => 'msg',
                'msg' => self::requiredString($payload['message'] ?? null, 'message'),
            ],
            default => throw new \InvalidArgumentException("Unsupported Wonlex configuration {$key}"),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function measurementInterval(string $metric, array $payload): array
    {
        return ['configs' => [$metric => ['interval' => (string)self::nonNegativeInt($payload['intervalMinutes'] ?? null, 'intervalMinutes')]]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function deviceToggle(string $configName, array $payload): array
    {
        return ['configs' => [$configName => ['switchState' => self::boolInt($payload['enabled'] ?? null, 'enabled')]]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function sleepSettings(array $payload): array
    {
        // O relógio quer as horas em `HHmmss`.
        return ['configs' => ['SleepIntervalOrSwitch' => [
            'switchState' => self::boolInt($payload['enabled'] ?? null, 'enabled'),
            'sleepStartTime' => str_replace(':', '', self::hhmm($payload['startTime'] ?? null, 'startTime')) . '00',
            'sleepEndTime' => str_replace(':', '', self::hhmm($payload['endTime'] ?? null, 'endTime')) . '00',
            'sleepTarget' => self::nonNegativeInt($payload['targetMinutes'] ?? null, 'targetMinutes'),
        ]]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function reminderThreshold(string $configName, string $wireField, string $field, array $payload): array
    {
        return ['configs' => [$configName => [
            'switchState' => self::boolInt($payload['enabled'] ?? null, 'enabled'),
            $wireField => self::nonNegativeFloat($payload[$field] ?? null, $field),
        ]]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function heartRateRange(string $configName, array $payload): array
    {
        return ['configs' => [$configName => [
            'switchState' => self::boolInt($payload['enabled'] ?? null, 'enabled'),
            'remindValue' => self::nonNegativeInt($payload['thresholdBpm'] ?? null, 'thresholdBpm'),
            'exerciseSwitchState' => self::boolInt($payload['exerciseEnabled'] ?? null, 'exerciseEnabled'),
            'exerciseHRMin' => self::nonNegativeInt($payload['exerciseMinBpm'] ?? null, 'exerciseMinBpm'),
            'exerciseHRMax' => self::nonNegativeInt($payload['exerciseMaxBpm'] ?? null, 'exerciseMaxBpm'),
            'exerciseRemindValue' => self::nonNegativeInt($payload['exerciseThresholdBpm'] ?? null, 'exerciseThresholdBpm'),
        ]]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function bloodPressureWarning(array $payload): array
    {
        return ['configs' => ['BPEarlyWarning' => [
            'switchState' => self::boolInt($payload['enabled'] ?? null, 'enabled'),
            'hpWarn' => self::nonNegativeInt($payload['systolicMmHg'] ?? null, 'systolicMmHg'),
            'LPWarn' => self::nonNegativeInt($payload['diastolicMmHg'] ?? null, 'diastolicMmHg'),
        ]]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function alarmClockList(array $payload): array
    {
        $items = $payload['alarmClockList'] ?? $payload['alarmClock'] ?? $payload['alarms'] ?? $payload['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \InvalidArgumentException('alarmClockList must be an array');
        }
        if (count($items) > self::ALARM_CLOCK_LIMIT) {
            throw new \InvalidArgumentException('alarmClockList must contain at most 10 items');
        }

        return array_map(static function (mixed $item): array {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('alarmClockList items must be objects');
            }
            $label = trim((string)($item['label'] ?? ''));
            $time = self::hhmm($item['startTime'] ?? $item['time'] ?? null, 'alarm startTime');
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
            if (
                $url !== '' && (
                filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
                )
            ) {
                throw new \InvalidArgumentException('alarm url must be a valid HTTP or HTTPS URL');
            }

            return array_filter([
                'label' => $label,
                'startTime' => $time,
                'week' => $week,
                'status' => (string)self::boolInt($item['status'] ?? $item['enabled'] ?? true, 'status'),
                'url' => $url,
            ], static fn (mixed $value): bool => $value !== '');
        }, $items);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function familyNumbers(mixed $contacts): array
    {
        if (!is_array($contacts) || !array_is_list($contacts)) {
            throw new \InvalidArgumentException('contacts must be an array');
        }
        if (count($contacts) > 10) {
            throw new \InvalidArgumentException('contacts must contain at most 10 values');
        }

        return array_map(static function (mixed $contact): array {
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
        }, $contacts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function sosNumbers(mixed $contacts): array
    {
        if (!is_array($contacts) || !array_is_list($contacts)) {
            throw new \InvalidArgumentException('numbers must be an array');
        }
        if (count($contacts) > 10) {
            throw new \InvalidArgumentException('numbers must contain at most 10 values');
        }

        return array_map(static function (mixed $contact): array {
            $contact = is_array($contact) ? $contact : ['phone' => $contact];
            $phone = self::requiredString($contact['phone'] ?? null, 'phone');
            return [
                'sosNumberId' => trim((string)($contact['sosNumberId'] ?? $contact['familyNumberId'] ?? substr(sha1($phone), 0, 8))),
                'name' => trim((string)($contact['name'] ?? '')),
                'phone' => $phone,
            ];
        }, $contacts);
    }
}
