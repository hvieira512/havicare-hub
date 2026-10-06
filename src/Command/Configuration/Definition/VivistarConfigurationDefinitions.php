<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

final class VivistarConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);

        return [
            $entry('sosContacts', 'BP12', 'Contactos SOS', 'sos_contacts', ['numbers'], ['AP12'], 'contacts', 10, 3),
            $entry('call_whitelist', 'BP14', 'Lista de chamadas autorizadas', 'call_whitelist', ['contacts'], ['AP14'], 'contacts', 20, 10, help: 'É também a agenda do relógio.'),
            $entry('whitelist_enabled', 'BP84', 'Restringir chamadas recebidas', 'toggle', ['enabled'], ['AP84'], 'contacts', 25, help: 'Só a lista de chamadas autorizadas consegue ligar.'),
            $entry('pushMessage', 'BP40', 'Enviar mensagem ao relógio', 'pushMessage', ['message'], ['AP40'], 'system', 5, transient: true),
            $entry('workingMode', 'BP33', 'Envio da localização', 'workingMode', ['mode'], ['AP33'], 'system', 10, null, [
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
            $entry('fallDetection', 'BP76', 'Deteção de queda', 'toggle', ['enabled'], ['AP76'], 'alerts', 10),
            $entry('fallSensitivity', 'BP77', 'Sensibilidade de queda', 'fallSensitivity', ['sensitivity'], ['AP77'], 'alerts', 20, null, [
                'sensitivity' => [
                    ['value' => 1, 'label' => 'Baixa'],
                    ['value' => 2, 'label' => 'Normal'],
                    ['value' => 3, 'label' => 'Alta'],
                ],
            ]),
            $entry('reminders', 'BP85', 'Lembretes / Alarmes', 'alarm_clock', ['masterEnabled', 'items'], ['AP85'], 'alerts', 30, null, [
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
            $entry('autoHealthMeasurement', 'BP86', 'Medição automática de saúde', 'intervalToggle', ['enabled', 'intervalMinutes'], ['AP86'], 'health', 10, help: 'Mede todos os sinais vitais de uma vez e envia-os.'),
        ];
    }
}
