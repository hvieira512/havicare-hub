<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

final class DiaperSensorCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'diaper_sensor';
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                // Nenhuma é pedível: o sensor é um beacon BLE e não aceita pedidos.
                'measurement' => [
                    'battery',
                    'diaper_moisture',
                    // Genérica: qualquer medidor de fraldas tem um nível de humidade, ao contrário dos 10 canais
                    // da `diaper_moisture`, que são do MONIT.
                    'diaper_moisture_level',
                    'diaper_condition',
                ],
                // Mesma forma que na pulseira, porque é o mesmo caminho de código.
                'sighting' => [
                    'proximity',
                ],
            ],
            'alarms' => [
                'event' => [
                    'change_required',
                    'check_required',
                    'proximity_alarm',
                ],
            ],
            'settings_system' => [
                // O sensor é um beacon que só transmite: o que esta muda é a regra com que o
                // hub deriva o estado da fralda, e não sai downlink nenhum.
                'hubSetting' => [
                    'diaper_sensitivity',
                ],
            ],
        ];
    }
}
