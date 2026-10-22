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
        $toggle = static fn(string $key, string $command, string $category, int $order): array => $entry(
            $key,
            $command,
            'toggle',
            ['enabled'],
            [$command],
            category: $category,
            order: $order,
        );

        return [
            $entry('uploadInterval', 'UPLOAD', 'number', ['intervalSeconds'], ['UPLOAD'], category: 'intervals', order: 10, options: ['min' => 60], bounds: ['intervalSeconds' => ['min' => 60, 'max' => 65535]]),
            $entry('sosContacts', 'SOS', 'sos_contacts', ['numbers'], ['SOS'], category: 'contacts', order: 10, limit: 3),
            $entry('whitelistGroup1', 'WHITELIST1', 'call_whitelist', ['numbers'], ['WHITELIST1'], category: 'contacts', order: 40, limit: 5),
            $entry('whitelistGroup2', 'WHITELIST2', 'call_whitelist', ['numbers'], ['WHITELIST2'], category: 'contacts', order: 50, limit: 5),
            $entry('languageTimezone', 'LZ', 'languageTimezone', ['language', 'utcOffsetHours'], ['LZ'], category: 'system', order: 20, options: [
                'language' => array_map(static fn(string $tag): array => ['value' => $tag], array_keys(FourPTouchPayloadBuilder::LANGUAGES)),
                'utcOffsetHours' => ['min' => -12, 'max' => 14, 'step' => 0.5],
                'default' => ['language' => 'pt', 'utcOffsetHours' => 0],
            ]),
            $toggle('sosSmsAlerts', 'SOSSMS', 'alerts', 10),
            $toggle('lowBatterySmsAlerts', 'LOWBAT', 'alerts', 20),
            $toggle('removeWatchAlarm', 'REMOVE', 'alerts', 30),
            $toggle('removeWatchSmsAlerts', 'REMOVESMS', 'alerts', 40),
            $entry('fallDownAlert', 'FALLDOWN', 'dualToggle', ['enabled', 'callCenterOnFall'], ['FALLDOWN'], category: 'alerts', order: 50),
            $entry('fallDownSensitivity', 'LSSET', 'fallSensitivityLevels', ['sensitivity'], ['LSSET'], category: 'alerts', order: 60, options: [
                'sensitivity' => $range(8),
                'levels' => [['value' => 6], ['value' => 8]],
            ]),
            $entry('takePills', 'TAKEPILLS', 'takePills', ['reminderSettings', 'reminderText', 'voiceData'], ['TAKEPILLS'], category: 'alerts', order: 70, limit: 3),
            $entry('healthAutoMeasurement', 'HEALTHAUTOSET', 'intervalToggle', ['enabled', 'intervalMinutes'], ['HEALTHAUTOSET'], category: 'health', order: 10, options: ['min' => 5], bounds: ['intervalMinutes' => ['min' => 5]]),
            $entry('walkTime', 'WALKTIME', 'timeRanges', ['ranges'], ['WALKTIME'], category: 'health', order: 20, limit: 3, options: ['default' => ['ranges' => ['08:10-09:30']]]),
            $entry('sleepTime', 'SLEEPTIME', 'timeRange', ['range'], ['SLEEPTIME'], category: 'health', order: 30),
            $entry('bodyTemperatureInterval', 'bodytemp', 'intervalHoursToggle', ['enabled', 'intervalHours'], ['bodytemp'], category: 'health', order: 40, bounds: ['intervalHours' => ['min' => 1, 'max' => 12]]),
            $entry('makeCall', 'CALL', 'makeCall', ['phone'], ['CALL'], category: 'system', order: 5, transient: true),
            $entry('monitorNumber', 'MONITOR', 'voiceMonitor', ['phone'], ['MONITOR'], category: 'system', order: 5, transient: true, destructive: true),
            $entry('centerNumber', 'CENTER', 'phone', ['phone'], ['CENTER'], category: 'contacts', order: 5),
            $entry('pushMessage', 'MESSAGE', 'pushMessage', ['message'], ['MESSAGE'], category: 'system', order: 5, transient: true, verb: 'send_message'),
            $entry('restartCommand', 'RESET', 'action', [], ['RESET'], category: 'system', order: 5, transient: true, destructive: true),
            $entry('powerOffCommand', 'POWEROFF', 'action', [], ['POWEROFF'], category: 'system', order: 5, transient: true, destructive: true),
            $entry('findDeviceCommand', 'FIND', 'action', [], ['FIND'], category: 'system', order: 5, transient: true),
            $entry('doNotDisturb', 'SILENCETIME', 'timeRanges', ['ranges'], ['SILENCETIME'], category: 'system', order: 60, limit: 4),
            // Sem `deviceStatus`: o `TS` pede-se no mosaico «Estado do dispositivo».
            $entry('alarmClock', 'REMIND', 'alarm_clock', ['alarms'], ['REMIND'], category: 'alerts', order: 5, limit: 3, options: [
                'mode' => $range(3),
                'days' => $range(7),
            ]),
            $entry('phonebook', 'PHBX2', 'phonebook', ['contacts'], ['PHBX2', 'DPHBX', 'PHB', 'PHB2'], category: 'contacts', order: 55, limit: 100),
            $entry('profile', 'profile', 'soundProfile', ['mode'], ['profile'], category: 'system', order: 55, options: [
                'mode' => array_map(static fn(string $mode): array => ['value' => $mode], array_keys(FourPTouchPayloadBuilder::SOUND_PROFILES)),
            ]),
            $toggle('rejectUnknownCalls', 'DEVREFUSEPHONESWITCH', 'contacts', 35),
        ];
    }
}
