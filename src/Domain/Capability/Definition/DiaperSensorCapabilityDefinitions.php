<?php

namespace Hub\Domain\Capability\Definition;

final class DiaperSensorCapabilityDefinitions
{
    public static function all(): array
    {
        return [
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'sortOrder' => 10, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'diaper_moisture', 'label' => 'Humidade da fralda', 'sortOrder' => 20, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // Genérica de propósito: qualquer medidor de fraldas tem um nível de humidade, ao
            // contrário dos 10 canais capacitivos da `diaper_moisture`, que são do MONIT.
            // `isRequestable` false como as outras -- o sensor é BLE e não aceita pedidos.
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'diaper_moisture_level', 'label' => 'Nível de humidade', 'sortOrder' => 25, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'diaper_condition', 'label' => 'Estado da fralda', 'sortOrder' => 30, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // Sai por avistamento, e não do sensor: é a força com que cada gateway o ouve.
            // Mesma forma que na pulseira, porque é o mesmo caminho de código.
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'proximity', 'label' => 'Proximidade', 'sortOrder' => 35, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'diaper_sensor', 'section' => 'alarms', 'key' => 'change_required', 'label' => 'Mudança necessária', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            // Configurável sem downlink: o sensor é um beacon BLE que só transmite, e o que
            // ela muda é a regra com que o hub deriva o estado da fralda.
            ['deviceType' => 'diaper_sensor', 'section' => 'settings_system', 'key' => 'diaper_sensitivity', 'label' => 'Sensibilidade dos alertas', 'sortOrder' => 50, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
        ];
    }
}
