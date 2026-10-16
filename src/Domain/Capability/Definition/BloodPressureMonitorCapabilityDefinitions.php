<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

final class BloodPressureMonitorCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'blood_pressure_monitor';
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                'measurement' => [
                    'blood_pressure' => 'Pressão arterial',
                    // O pulso de cada medição, à parte como nos relógios.
                    'heart_rate' => 'Frequência cardíaca',
                ],
            ],
            'health' => [
                'action' => [
                    'read_blood_pressure_history' => 'Ler a memória',
                ],
            ],
        ];
    }
}
