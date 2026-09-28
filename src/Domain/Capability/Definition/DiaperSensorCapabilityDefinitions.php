<?php

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
                'reading' => [
                    'battery' => 'Bateria',
                    'diaper_moisture' => 'Humidade da fralda',
                    // Genérica de propósito: qualquer medidor de fraldas tem um nível de
                    // humidade, ao contrário dos 10 canais capacitivos da `diaper_moisture`,
                    // que são do MONIT.
                    'diaper_moisture_level' => 'Nível de humidade',
                    'diaper_condition' => 'Estado da fralda',
                    // Sai por avistamento, e não do sensor: é a força com que cada gateway o
                    // ouve. Mesma forma que na pulseira, porque é o mesmo caminho de código.
                    'proximity' => 'Proximidade',
                ],
            ],
            'alarms' => [
                'event' => [
                    'change_required' => 'Mudança necessária',
                ],
            ],
            // Configurável sem downlink: o sensor é um beacon BLE que só transmite, e o que
            // ela muda é a regra com que o hub deriva o estado da fralda.
            'settings_system' => [
                'setting' => [
                    'diaper_sensitivity' => 'Sensibilidade dos alertas',
                ],
            ],
        ];
    }
}
