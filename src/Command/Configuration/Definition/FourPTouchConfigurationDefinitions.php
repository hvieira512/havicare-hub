<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

final class FourPTouchConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);
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
            $entry('languageTimezone', 'LZ', 'languageTimezone', ['language', 'timeZone'], ['LZ'], category: 'system', order: 20, bounds: ['language' => ['min' => 0, 'max' => 36]]),
            $toggle('sosSmsAlerts', 'SOSSMS', 'alerts', 10),
            $toggle('lowBatterySmsAlerts', 'LOWBAT', 'alerts', 20),
            $toggle('removeWatchAlarm', 'REMOVE', 'alerts', 30),
            $toggle('removeWatchSmsAlerts', 'REMOVESMS', 'alerts', 40),
            $entry('fallDownAlert', 'FALLDOWN', 'dualToggle', ['enabled', 'callCenterOnFall'], ['FALLDOWN'], category: 'alerts', order: 50),
            // hitcare: as etiquetas ficam porque o hitCare as mostra do `_meta`; saem quando ele traduzir os valores.
            $entry('fallDownSensitivity', 'LSSET', 'fallSensitivityLevels', ['sensitivity'], ['LSSET'], category: 'alerts', order: 60, options: [
                'sensitivity' => [
                    ['value' => 1, 'label' => 'Máxima'],
                    ['value' => 2, 'label' => 'Muito Alta'],
                    ['value' => 3, 'label' => 'Alta'],
                    ['value' => 4, 'label' => 'Moderada'],
                    ['value' => 5, 'label' => 'Baixa'],
                    ['value' => 6, 'label' => 'Muito Baixa'],
                    ['value' => 7, 'label' => 'Quase Mínima'],
                    ['value' => 8, 'label' => 'Mínima'],
                ],
                'levels' => [['value' => 6], ['value' => 8]],
            ]),
            $entry('takePills', 'TAKEPILLS', 'takePills', ['reminderSettings', 'reminderText', 'voiceData'], ['TAKEPILLS'], category: 'alerts', order: 70, limit: 3),
            $entry('healthAutoMeasurement', 'HEALTHAUTOSET', 'intervalToggle', ['enabled', 'intervalMinutes'], ['HEALTHAUTOSET'], category: 'health', order: 10, options: ['min' => 5], bounds: ['intervalMinutes' => ['min' => 5]]),
            $entry('walkTime', 'WALKTIME', 'timeRanges', ['ranges'], ['WALKTIME'], category: 'health', order: 20, limit: 3),
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
            // hitcare: as etiquetas ficam porque o hitCare as mostra do `_meta`; saem quando ele traduzir os valores.
            $entry('alarmClock', 'REMIND', 'alarm_clock', ['alarms'], ['REMIND'], category: 'alerts', order: 5, limit: 3, options: [
                'mode' => [
                    ['value' => 1, 'label' => 'Uma vez'],
                    ['value' => 2, 'label' => 'Todos os dias'],
                    ['value' => 3, 'label' => 'Personalizado'],
                ],
                'days' => [
                    ['value' => 1, 'label' => 'Seg'],
                    ['value' => 2, 'label' => 'Ter'],
                    ['value' => 3, 'label' => 'Qua'],
                    ['value' => 4, 'label' => 'Qui'],
                    ['value' => 5, 'label' => 'Sex'],
                    ['value' => 6, 'label' => 'Sáb'],
                    ['value' => 7, 'label' => 'Dom'],
                ],
            ]),
            $entry('phonebook', 'PHBX2', 'phonebook', ['contacts'], ['PHBX2', 'DPHBX', 'PHB', 'PHB2'], category: 'contacts', order: 55, limit: 100),
            $entry('profile', 'profile', 'soundProfile', ['mode'], ['profile'], category: 'system', order: 55, options: [
                'mode' => [['value' => 1], ['value' => 2], ['value' => 3], ['value' => 4]],
            ]),
            $toggle('rejectUnknownCalls', 'DEVREFUSEPHONESWITCH', 'contacts', 35),
        ];
    }
}
