<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

final class ThermometerCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'thermometer';
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                'measurement' => [
                    'temperature',
                ],
            ],
        ];
    }
}
