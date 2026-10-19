<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

final class PulseOximeterCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'pulse_oximeter';
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                'measurement' => [
                    'blood_oxygen' => 'Oxigénio no sangue',
                    // O pulso de cada medição, à parte como no tensiómetro.
                    'heart_rate' => 'Frequência cardíaca',
                ],
            ],
        ];
    }
}
