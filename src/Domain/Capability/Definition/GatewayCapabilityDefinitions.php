<?php

namespace Hub\Domain\Capability\Definition;

final class GatewayCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'gateway';
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                'measurement' => [
                    'connectivity' => 'Conectividade',
                    'battery' => 'Bateria',
                    'location' => 'Localização',
                ],
            ],
        ];
    }
}
