<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

final class VivistarConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);

        // hitcare: as etiquetas das opções do modo, da sensibilidade e dos lembretes ficam porque o
        // hitCare as mostra do `_meta`; saem quando ele traduzir os valores.
        return [
            $entry('sosContacts', 'BP12', 'sos_contacts', ['numbers'], ['AP12'], category: 'contacts', order: 10, limit: 3),
            $entry('call_whitelist', 'BP14', 'call_whitelist', ['contacts'], ['AP14'], category: 'contacts', order: 20, limit: 10),
            $entry('whitelist_enabled', 'BP84', 'toggle', ['enabled'], ['AP84'], category: 'contacts', order: 25),
            $entry('pushMessage', 'BP40', 'pushMessage', ['message'], ['AP40'], category: 'system', order: 5, transient: true, verb: 'send_message'),
            $entry('workingMode', 'BP33', 'workingMode', ['mode'], ['AP33'], category: 'system', order: 10, options: [
                'mode' => [
                    ['value' => 1, 'label' => 'A cada 15 min'],
                    ['value' => 2, 'label' => 'A cada 60 min'],
                    ['value' => 3, 'label' => 'A cada minuto, com GPS'],
                    ['value' => 8, 'label' => 'Personalizado', 'fields' => [
                        'intervalSeconds' => ['type' => 'integer', 'min' => 30],
                        'gpsEnabled' => ['type' => 'boolean'],
                    ]],
                ],
            ]),
            $entry('fallDetection', 'BP76', 'toggle', ['enabled'], ['AP76'], category: 'alerts', order: 10),
            $entry('fallSensitivity', 'BP77', 'fallSensitivity', ['sensitivity'], ['AP77'], category: 'alerts', order: 20, options: [
                'sensitivity' => [
                    ['value' => 1, 'label' => 'Baixa'],
                    ['value' => 2, 'label' => 'Normal'],
                    ['value' => 3, 'label' => 'Alta'],
                ],
            ]),
            $entry('reminders', 'BP85', 'alarm_clock', ['masterEnabled', 'items'], ['AP85'], category: 'alerts', order: 30, options: [
                'days' => [
                    ['value' => 1, 'label' => 'Seg'],
                    ['value' => 2, 'label' => 'Ter'],
                    ['value' => 3, 'label' => 'Qua'],
                    ['value' => 4, 'label' => 'Qui'],
                    ['value' => 5, 'label' => 'Sex'],
                    ['value' => 6, 'label' => 'Sáb'],
                    ['value' => 7, 'label' => 'Dom'],
                ],
                'type' => [
                    ['value' => 1, 'label' => 'Medicação'],
                    ['value' => 2, 'label' => 'Água'],
                    ['value' => 3, 'label' => 'Sedentarismo'],
                ],
            ]),
            $entry('autoHealthMeasurement', 'BP86', 'intervalToggle', ['enabled', 'intervalMinutes'], ['AP86'], category: 'health', order: 10, bounds: ['intervalMinutes' => ['min' => 0]]),
        ];
    }
}
