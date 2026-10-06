<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

final class GatewayCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'gateway';
    }

    /** O MKGW3 só diz por onde está ligado; o MKGW4 tem bateria e GPS. */
    protected static function publishedBy(): array
    {
        return [
            'battery' => ['moko-mkgw4'],
            'location' => ['moko-mkgw4'],
        ];
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
