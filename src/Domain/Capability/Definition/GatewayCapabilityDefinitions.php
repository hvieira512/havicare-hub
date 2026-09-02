<?php

namespace Hub\Domain\Capability\Definition;

final class GatewayCapabilityDefinitions
{
    public static function all(): array
    {
        return [
            ['deviceType' => 'gateway', 'section' => 'telemetry', 'key' => 'connectivity', 'label' => 'Conectividade', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'gateway', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'gateway', 'section' => 'telemetry', 'key' => 'location', 'label' => 'Localização', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
        ];
    }
}
