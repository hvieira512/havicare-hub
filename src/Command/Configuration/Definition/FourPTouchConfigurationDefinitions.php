<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

use Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder;

final class FourPTouchConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);
        $range = static fn(int $last): array => array_map(static fn(int $value): array => ['value' => $value], range(1, $last));
        $toggle = static fn(string $key, string $command, string $category, int $order, string $capability): array => $entry(
            $key,
            $command,
            'toggle',
            ['enabled'],
            [$command],
            category: $category,
            order: $order,
            capability: $capability,
        );

        return [
            $entry('uploadInterval', 'UPLOAD', 'number', ['intervalSeconds'], ['UPLOAD'], category: 'intervals', order: 10, options: ['min' => 60], bounds: ['intervalSeconds' => ['min' => 60, 'max' => 65535]], capability: 'location_reporting_interval'),
            $entry('sosContacts', 'SOS', 'sos_contacts', ['numbers'], ['SOS'], category: 'contacts', order: 10, limit: 3, capability: 'sos_contacts'),
            $entry('whitelistGroup1', 'WHITELIST1', 'call_whitelist', ['numbers'], ['WHITELIST1'], category: 'contacts', order: 40, limit: 5, capability: 'call_whitelist'),
            $entry('whitelistGroup2', 'WHITELIST2', 'call_whitelist', ['numbers'], ['WHITELIST2'], category: 'contacts', order: 50, limit: 5, capability: 'call_whitelist'),
            $entry('languageTimezone', 'LZ', 'languageTimezone', ['language', 'utcOffsetHours'], ['LZ'], category: 'system', order: 20, capability: 'language_timezone', options: [
                'language' => array_map(static fn(string $tag): array => ['value' => $tag], array_keys(FourPTouchPayloadBuilder::LANGUAGES)),
                'utcOffsetHours' => ['min' => -12, 'max' => 14, 'step' => 0.5],
                'default' => ['language' => 'pt', 'utcOffsetHours' => 0],
            ]),
            $toggle('sosSmsAlerts', 'SOSSMS', 'alerts', 10, 'sos_sms_alert'),
            $toggle('lowBatterySmsAlerts', 'LOWBAT', 'alerts', 20, 'low_battery_alert'),
            $toggle('removeWatchAlarm', 'REMOVE', 'alerts', 30, 'remove_watch_alarm'),
            $toggle('removeWatchSmsAlerts', 'REMOVESMS', 'alerts', 40, 'remove_watch_sms_alert'),
            $entry('fallDownAlert', 'FALLDOWN', 'dualToggle', ['enabled', 'callCenterOnFall'], ['FALLDOWN'], category: 'alerts', order: 50, capability: 'fall_detection'),
            $entry('fallDownSensitivity', 'LSSET', 'fallSensitivityLevels', ['sensitivity'], ['LSSET'], category: 'alerts', order: 60, capability: 'fall_sensitivity', options: [
                'sensitivity' => $range(8),
                'levels' => [['value' => 6], ['value' => 8]],
            ]),
            $entry('takePills', 'TAKEPILLS', 'takePills', ['reminderSettings', 'reminderText', 'voiceData'], ['TAKEPILLS'], category: 'alerts', order: 70, limit: 3, capability: 'medication_reminders'),
            $entry('healthAutoMeasurement', 'HEALTHAUTOSET', 'intervalToggle', ['enabled', 'intervalMinutes'], ['HEALTHAUTOSET'], category: 'health', order: 10, options: ['min' => 5], bounds: ['intervalMinutes' => ['min' => 5]], capability: 'auto_vitals_interval'),
            $entry('walkTime', 'WALKTIME', 'timeRanges', ['ranges'], ['WALKTIME'], category: 'health', order: 20, limit: 3, options: ['default' => ['ranges' => ['08:10-09:30']]], capability: 'pedometer_schedule'),
            $entry('sleepTime', 'SLEEPTIME', 'timeRange', ['range'], ['SLEEPTIME'], category: 'health', order: 30, capability: 'sleep_monitoring'),
            $entry('bodyTemperatureInterval', 'bodytemp', 'intervalHoursToggle', ['enabled', 'intervalHours'], ['bodytemp'], category: 'health', order: 40, bounds: ['intervalHours' => ['min' => 1, 'max' => 12]], capability: 'temperature_measurement_interval'),
            $entry('makeCall', 'CALL', 'makeCall', ['phone'], ['CALL'], category: 'system', order: 5, transient: true, capability: 'make_call'),
            $entry('monitorNumber', 'MONITOR', 'voiceMonitor', ['phone'], ['MONITOR'], category: 'system', order: 5, transient: true, destructive: true, capability: 'monitor_number'),
            $entry('centerNumber', 'CENTER', 'phone', ['phone'], ['CENTER'], category: 'contacts', order: 5, capability: 'center_number'),
            $entry('pushMessage', 'MESSAGE', 'pushMessage', ['message'], ['MESSAGE'], category: 'system', order: 5, transient: true, verb: 'send_message', capability: 'push_message'),
            $entry('restartCommand', 'RESET', 'action', [], ['RESET'], category: 'system', order: 5, transient: true, destructive: true, capability: 'restart_device'),
            $entry('powerOffCommand', 'POWEROFF', 'action', [], ['POWEROFF'], category: 'system', order: 5, transient: true, destructive: true, capability: 'power_off'),
            $entry('findDeviceCommand', 'FIND', 'action', [], ['FIND'], category: 'system', order: 5, transient: true, capability: 'find_device'),
            $entry('doNotDisturb', 'SILENCETIME', 'timeRanges', ['ranges'], ['SILENCETIME'], category: 'system', order: 60, limit: 4, capability: 'do_not_disturb'),
            // Sem `deviceStatus`: o `TS` pede-se no mosaico «Estado do dispositivo».
            $entry('alarmClock', 'REMIND', 'alarm_clock', ['alarms'], ['REMIND'], category: 'alerts', order: 5, limit: 3, capability: 'alarm_clock', options: [
                'mode' => $range(3),
                'days' => $range(7),
            ]),
            $entry('phonebook', 'PHBX2', 'phonebook', ['contacts'], ['PHBX2', 'DPHBX', 'PHB', 'PHB2'], category: 'contacts', order: 55, limit: 100),
            $entry('profile', 'profile', 'soundProfile', ['mode'], ['profile'], category: 'system', order: 55, capability: 'sound_profile', options: [
                'mode' => array_map(static fn(string $mode): array => ['value' => $mode], array_keys(FourPTouchPayloadBuilder::SOUND_PROFILES)),
            ]),
            $toggle('rejectUnknownCalls', 'DEVREFUSEPHONESWITCH', 'contacts', 35, 'whitelist_enabled'),
        ];
    }
}
