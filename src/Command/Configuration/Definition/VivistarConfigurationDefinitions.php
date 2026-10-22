<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

final class VivistarConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);
        $range = static fn(int $last): array => array_map(static fn(int $value): array => ['value' => $value], range(1, $last));

        return [
            $entry('sosContacts', 'BP12', 'sos_contacts', ['numbers'], ['AP12'], category: 'contacts', order: 10, limit: 3),
            $entry('call_whitelist', 'BP14', 'call_whitelist', ['contacts'], ['AP14'], category: 'contacts', order: 20, limit: 10),
            $entry('whitelist_enabled', 'BP84', 'toggle', ['enabled'], ['AP84'], category: 'contacts', order: 25),
            $entry('pushMessage', 'BP40', 'pushMessage', ['message'], ['AP40'], category: 'system', order: 5, transient: true, verb: 'send_message'),
            $entry('workingMode', 'BP33', 'workingMode', ['mode'], ['AP33'], category: 'system', order: 10, options: [
                'mode' => [
                    ['value' => 'normal'],
                    ['value' => 'power_saving'],
                    ['value' => 'emergency'],
                    ['value' => 'custom', 'fields' => [
                        'intervalSeconds' => ['type' => 'integer', 'min' => 30],
                        'gpsEnabled' => ['type' => 'boolean'],
                    ]],
                ],
            ]),
            $entry('fallDetection', 'BP76', 'toggle', ['enabled'], ['AP76'], category: 'alerts', order: 10),
            $entry('fallSensitivity', 'BP77', 'fallSensitivity', ['sensitivity'], ['AP77'], category: 'alerts', order: 20, options: [
                'sensitivity' => $range(3),
            ]),
            $entry('reminders', 'BP85', 'alarm_clock', ['masterEnabled', 'items'], ['AP85'], category: 'alerts', order: 30, options: [
                'days' => $range(7),
                'type' => $range(3),
            ]),
            $entry('autoHealthMeasurement', 'BP86', 'intervalToggle', ['enabled', 'intervalMinutes'], ['AP86'], category: 'health', order: 10, bounds: ['intervalMinutes' => ['min' => 0]]),
        ];
    }
}
