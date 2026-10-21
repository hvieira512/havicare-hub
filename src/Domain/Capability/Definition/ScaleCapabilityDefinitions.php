<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

final class ScaleCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'scale';
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                'measurement' => [
                    'battery',
                    'weight',
                    // A mesma da pulseira e do relógio: a balança mede-a pelos elétrodos.
                    'heart_rate',
                    'body_impedance',
                    'body_impedance_channel',
                    // Calculada no hub pela impedância e pelo perfil de quem está em linha.
                    'body_composition',
                    'weighing_status',
                ],
            ],
            'health' => [
                'setting' => [
                    'heart_rate_measurement',
                    'impedance_measurement',
                    // O mesmo registo que os «Dados para cálculo» da pulseira, em lista.
                    'scale_users',
                ],
                // Pôr em linha é o que atribui a pesagem seguinte, e sem ele a FC não é medida.
                'action' => [
                    'select_scale_user',
                    'read_scale_history',
                ],
            ],
            'settings_system' => [
                'setting' => [
                    'weight_unit',
                ],
                'action' => [
                    'sync_configuration',
                    'calibrate_clock',
                    'restart_device',
                ],
            ],
        ];
    }
}
