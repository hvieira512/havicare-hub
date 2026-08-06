<?php

namespace Hub\Domain\Capability\Definition;

final class DiaperSensorCapabilityDefinitions
{
    public static function all(): array
    {
        return [
            ['deviceType' => 'diaper_sensor', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'sortOrder' => 10, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'diaper_sensor', 'section' => 'health', 'key' => 'diaper_moisture', 'label' => 'Humidade da fralda', 'sortOrder' => 20, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'diaper_sensor', 'section' => 'health', 'key' => 'diaper_condition', 'label' => 'Estado da fralda', 'sortOrder' => 30, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'diaper_sensor', 'section' => 'alarms', 'key' => 'change_required', 'label' => 'Mudança necessária', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ];
    }
}
