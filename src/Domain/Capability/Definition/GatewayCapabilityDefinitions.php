<?php

namespace Hub\Domain\Capability\Definition;

final class GatewayCapabilityDefinitions
{
    public static function all(): array
    {
        return [
            ['deviceType' => 'gateway', 'section' => 'telemetry', 'key' => 'connectivity', 'label' => 'Conectividade', 'sortOrder' => 10, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'gateway', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'sortOrder' => 20, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'gateway', 'section' => 'telemetry', 'key' => 'location', 'label' => 'Localização', 'sortOrder' => 30, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
        ];
    }
}
