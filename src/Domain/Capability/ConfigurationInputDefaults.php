<?php

namespace Hub\Domain\Capability;

/**
 * The payload a capability starts from when a device has never stored one.
 *
 * Keyed by the input type of the protocol's configuration entry, so the
 * dashboard renders a usable form instead of an empty one. Split out of
 * DeviceCapabilityPresenter and CapabilityRegistry, which each carried their
 * own copy of this table; the copies had already drifted apart.
 */
final class ConfigurationInputDefaults
{
    /**
     * @param array<string, mixed> $entry a protocol configuration catalog entry
     * @return array<string, mixed>
     */
    public static function forEntry(array $entry): array
    {
        $input = (string)($entry['input'] ?? 'json');
        $field = static fn(int $index = 0): string => (string)($entry['fields'][$index] ?? '');

        return match ($input) {
            'toggle' => [($field(0) ?: 'enabled') => true],
            'number' => [($field(0) ?: 'value') => 0],
            'phone' => [($field(0) ?: 'phone') => ''],
            'text' => [($field(0) ?: 'value') => ''],
            'pushMessage' => ['message' => ''],
            'makeCall' => ['phone' => ''],
            'resetAction', 'requestAction' => [],
            'intervalToggle' => ['enabled' => true, 'intervalMinutes' => 60],
            'intervalHoursToggle' => ['enabled' => true, 'intervalHours' => 2],
            'workingMode' => ['mode' => 1],
            'bloodPressure' => ['systolic' => 120, 'diastolic' => 80],
            // BPEarlyWarning carries a systolic and a diastolic threshold, and
            // the payload builder requires both.
            'wonlexBloodPressureWarning' => ['switchState' => true, 'hpWarn' => 135, 'LPWarn' => 90],
            'languageTimezone' => ['language' => 0, 'timeZone' => '0'],
            'dualToggle' => ['enabled' => true, 'callCenterOnFall' => false],
            'fallSensitivityLevels' => ['sensitivity' => 5, 'levels' => 8],
            'timeRanges' => ['ranges' => ['08:10-09:30']],
            'timeRange' => ['range' => '21:10-07:30'],
            'wonlexSleepSettings' => [
                'switchState' => true,
                'sleepStartTime' => '220000',
                'sleepEndTime' => '100000',
                'sleepTarget' => 480,
            ],
            'wonlexReminderThreshold' => ['switchState' => true, ($field(1) ?: 'reminderValue') => 90],
            'wonlexHeartRateRange' => [
                'switchState' => true,
                'remindValue' => 120,
                'exerciseSwitchState' => true,
                'exerciseHRMin' => 100,
                'exerciseHRMax' => 140,
                'exerciseRemindValue' => 140,
            ],
            'list' => ['numbers' => array_fill(0, max(1, (int)($entry['limit'] ?? 3)), '')],
            'contacts' => ['contacts' => [['name' => '', 'phone' => '']]],
            'takePills' => [
                'reminderSettings' => [
                    ['time' => '08:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '09:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '10:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                ],
                'number' => 1,
                'reminderText' => '',
                'voiceData' => '',
                'voiceMimeType' => 'audio/webm',
            ],
            'soundProfile' => ['mode' => 1],
            default => [],
        };
    }
}
