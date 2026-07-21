<?php

namespace Hub\Command\Configuration\Definition;

final class VivistarConfigurationDefinitions
{
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);

        return [
            $entry('sosContacts', 'BP12', 'Contactos SOS', 'list', ['numbers'], ['AP12'], 'contacts', 10, 3),
            $entry('call_whitelist', 'BP14', 'Lista branca', 'contacts', ['contacts'], ['AP14'], 'contacts', 20, 10),
            $entry('whitelist_enabled', 'BP84', 'Filtro da lista telefónica', 'toggle', ['enabled'], ['AP84'], 'contacts', 25),
            $entry('pushMessage', 'BP40', 'Enviar mensagem ao relógio', 'pushMessage', ['message'], ['AP40'], 'system', 5) + ['transient' => true],
            $entry('workingMode', 'BP33', 'Modo de trabalho', 'workingMode', ['mode'], ['AP33'], 'system', 10, null, [
                'mode' => [
                    ['value' => 1, 'label' => 'Normal'],
                    ['value' => 2, 'label' => 'Poupança'],
                    ['value' => 3, 'label' => 'Emergência'],
                    ['value' => 8, 'label' => 'Personalizado', 'fields' => [
                        'intervalSeconds' => ['type' => 'integer', 'min' => 30],
                        'gpsEnabled' => ['type' => 'boolean'],
                    ]],
                ],
            ]),
            $entry('fallDetection', 'BP76', 'Deteção de queda', 'toggle', ['enabled'], ['AP76'], 'alerts', 10),
            $entry('fallSensitivity', 'BP77', 'Sensibilidade de queda', 'fallSensitivity', ['sensitivity'], ['AP77'], 'alerts', 20, null, [
                'sensitivity' => [
                    ['value' => 1, 'label' => 'Baixa'],
                    ['value' => 2, 'label' => 'Normal'],
                    ['value' => 3, 'label' => 'Alta'],
                ],
            ]),
            $entry('reminders', 'BP85', 'Lembretes / Alarmes', 'reminders', ['masterEnabled', 'items'], ['AP85'], 'alerts', 30, null, [
                'days' => [
                    ['value' => 1, 'label' => 'Seg'],
                    ['value' => 2, 'label' => 'Ter'],
                    ['value' => 3, 'label' => 'Qua'],
                    ['value' => 4, 'label' => 'Qui'],
                    ['value' => 5, 'label' => 'Sex'],
                    ['value' => 6, 'label' => 'Sab'],
                    ['value' => 7, 'label' => 'Dom'],
                ],
                'type' => [
                    ['value' => 1, 'label' => 'Medicação'],
                    ['value' => 2, 'label' => 'Água'],
                    ['value' => 3, 'label' => 'Sedentarismo'],
                ],
            ]),
            $entry('autoHealthMeasurement', 'BP86', 'Medição automática de saúde', 'intervalToggle', ['enabled', 'intervalMinutes'], ['AP86'], 'health', 10),
        ];
    }
}
