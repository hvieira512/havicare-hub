<?php

namespace Hub\Domain\Capability\Definition;

final class DiaperSensorCapabilityDefinitions
{
    public static function all(): array
    {
        return [
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'sortOrder' => 10, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'diaper_moisture', 'label' => 'Humidade da fralda', 'sortOrder' => 20, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // Generica de proposito: qualquer medidor de fraldas tem um nivel de humidade, ao
            // contrario dos 10 canais capacitivos da `diaper_moisture`, que sao do MONIT.
            // `isRequestable` false como as outras -- o sensor e BLE e nao aceita pedidos.
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'diaper_moisture_level', 'label' => 'Nível de humidade', 'sortOrder' => 25, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'diaper_condition', 'label' => 'Estado da fralda', 'sortOrder' => 30, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'diaper_sensor', 'section' => 'alarms', 'key' => 'change_required', 'label' => 'Mudança necessária', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ];
    }
}
