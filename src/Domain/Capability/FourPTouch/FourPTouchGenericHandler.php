<?php

namespace Hub\Domain\Capability\FourPTouch;

use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Protocol-specific fallback for generic 4P Touch capabilities that do not
 * have their own dedicated contract yet.
 */
final class FourPTouchGenericHandler
{
    use CapabilityHelpers;

    /**
     * Generic keys that should preserve a different native key only for
     * internal/native response tracking on 4P Touch.
     *
     * @var array<string, string>
     */
    private const RESPONSE_NATIVE_KEYS = [
        'make_call' => 'makeCall',
        'reset_device' => 'resetCommand',
        'power_off' => 'powerOffCommand',
        'find_device' => 'findDeviceCommand',
        'device_password' => 'devicePassword',
        'sound_profile' => 'soundProfile',
        'call_in_restriction' => 'callInRestriction',
    ];

    public static function publicKeyToNativeKey(string $key): ?string
    {
        return match (trim($key)) {
            'alarm_clock' => 'alarmClock',
            'fall_sensitivity' => 'fallDownSensitivity',
            'location_reporting_interval' => 'uploadInterval',
            'monitor_number' => 'monitorNumber',
            'center_number' => 'centerNumber',
            'sos_sms_alert' => 'sosSmsAlerts',
            'low_battery_alert' => 'lowBatterySmsAlerts',
            'remove_watch_alarm' => 'removeWatchAlarm',
            'remove_watch_sms_alert' => 'removeWatchSmsAlerts',
            'fall_detection' => 'fallDownAlert',
            'medication_reminders' => 'takePills',
            'auto_vitals_interval' => 'healthAutoMeasurement',
            'pedometer_schedule' => 'walkTime',
            'sleep_monitoring' => 'sleepTime',
            'temperature_measurement_interval' => 'bodyTemperatureInterval',
            'power_off' => 'powerOffCommand',
            'push_message' => 'pushMessage',
            'make_call' => 'makeCall',
            'reset_device' => 'resetCommand',
            'find_device' => 'findDeviceCommand',
            'firmwareVersion' => 'firmwareVersion',
            'deviceStatus' => 'deviceStatus',
            'device_password' => 'devicePassword',
            'language_timezone' => 'languageTimezone',
            'call_in_restriction' => 'callInRestriction',
            'sound_profile' => 'soundProfile',
            'whitelist_enabled' => 'whitelistSwitch',
            'do_not_disturb' => 'doNotDisturb',
            default => null,
        };
    }

    public static function nativeKeyToGenericKey(string $key): ?string
    {
        return match (trim($key)) {
            'alarmClock', 'reminders' => 'alarm_clock',
            'takePills' => 'medication_reminders',
            'sosContacts', 'sosNumber1', 'sosNumber2', 'sosNumber3' => 'sos_contacts',
            'whitelistSwitch' => 'whitelist_enabled',
            'whitelistGroup1', 'whitelistGroup2' => 'call_whitelist',
            'fallDownSensitivity' => 'fall_sensitivity',
            'lowBatterySmsAlerts' => 'low_battery_alert',
            'fallDownAlert' => 'fall_detection',
            'sosSmsAlerts' => 'sos_sms_alert',
            'uploadInterval' => 'location_reporting_interval',
            'monitorNumber' => 'monitor_number',
            'centerNumber' => 'center_number',
            'healthAutoMeasurement' => 'auto_vitals_interval',
            'walkTime' => 'pedometer_schedule',
            'sleepTime' => 'sleep_monitoring',
            'bodyTemperatureInterval' => 'temperature_measurement_interval',
            'powerOffCommand' => 'power_off',
            'pushMessage' => 'push_message',
            'makeCall' => 'make_call',
            'resetCommand' => 'reset_device',
            'findDeviceCommand' => 'find_device',
            'firmwareVersion' => 'firmwareVersion',
            'deviceStatus' => 'deviceStatus',
            'devicePassword' => 'device_password',
            'languageTimezone' => 'language_timezone',
            'callInRestriction' => 'call_in_restriction',
            'soundProfile' => 'sound_profile',
            'doNotDisturb' => 'do_not_disturb',
            default => null,
        };
    }

    public function fromNative(string $genericKey, string $nativeKey, array $desired): mixed
    {
        if ($genericKey === 'fall_sensitivity' && $nativeKey === 'fallDownSensitivity') {
            return [
                'sensitivity' => (int)($desired['sensitivity'] ?? $desired['sensitivityLevel'] ?? 5),
                'levels' => (int)($desired['levels'] ?? $desired['totalLevels'] ?? 8),
            ];
        }

        return $desired;
    }

    /**
     * Convert a generic capability payload to the native 4P Touch payload.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toNative(string $genericKey, mixed $value): array
    {
        return match ($genericKey) {
            'location_reporting_interval' => ['uploadInterval' => self::requireObjectValue($value, 'uploadInterval')],
            'monitor_number' => ['monitorNumber' => ['phone' => self::requireStringValue($value, 'phone')]],
            'device_password' => ['devicePassword' => self::requireObjectValue($value, 'devicePassword')],
            'language_timezone' => ['languageTimezone' => self::requireObjectValue($value, 'languageTimezone')],
            'sos_sms_alert' => ['sosSmsAlerts' => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'low_battery_alert' => ['lowBatterySmsAlerts' => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'remove_watch_alarm' => ['removeWatchAlarm' => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'remove_watch_sms_alert' => ['removeWatchSmsAlerts' => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'whitelist_enabled' => ['whitelistSwitch' => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'fall_detection' => ['fallDownAlert' => self::requireObjectValue($value, 'fallDownAlert')],
            'fall_sensitivity' => ['fallDownSensitivity' => self::requireObjectValue($value, 'fallDownSensitivity')],
            'auto_vitals_interval' => ['healthAutoMeasurement' => self::requireObjectValue($value, 'healthAutoMeasurement')],
            'pedometer_schedule' => ['walkTime' => ['ranges' => self::requireListValue(is_array($value) && array_key_exists('ranges', $value) ? $value['ranges'] : $value, 'ranges')]],
            'sleep_monitoring' => ['sleepTime' => ['range' => self::requireStringField($value, 'range')]],
            'temperature_measurement_interval' => ['bodyTemperatureInterval' => self::requireObjectValue($value, 'bodyTemperatureInterval')],
            'make_call' => ['makeCall' => ['phone' => self::requireStringField($value, 'phone')]],
            'center_number' => ['centerNumber' => ['phone' => self::requireStringField($value, 'phone')]],
            'push_message' => ['pushMessage' => ['message' => self::requireStringField($value, 'message')]],
            'reset_device' => ['resetCommand' => []],
            'power_off' => ['powerOffCommand' => []],
            'find_device' => ['findDeviceCommand' => []],
            default => throw new \InvalidArgumentException("Unsupported four-p-touch capability {$genericKey}"),
        };
    }

    /**
     * @return list<string>
     */
    public function nativeKeyAliases(): array
    {
        return array_keys(self::RESPONSE_NATIVE_KEYS);
    }

    public function nativeKeyForResponse(string $genericKey): ?string
    {
        return in_array($genericKey, $this->nativeKeyAliases(), true)
            ? $this->responseNativeKey($genericKey)
            : null;
    }

    private function responseNativeKey(string $genericKey): ?string
    {
        return self::RESPONSE_NATIVE_KEYS[$genericKey] ?? null;
    }
}
