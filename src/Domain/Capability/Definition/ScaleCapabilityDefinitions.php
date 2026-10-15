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
                    'battery' => 'Bateria',
                    'weight' => 'Peso',
                    // A mesma da pulseira e do relógio: a balança mede-a pelos elétrodos.
                    'heart_rate' => 'Frequência cardíaca',
                    'body_impedance' => 'Impedância corporal',
                    'body_impedance_channel' => 'Impedância por canal',
                    'weighing_status' => 'Estado da pesagem',
                ],
            ],
            'health' => [
                'setting' => [
                    'heart_rate_measurement' => 'Medir a frequência cardíaca',
                    'impedance_measurement' => 'Medir a impedância',
                    // O mesmo registo que os «Dados para cálculo» da pulseira, em lista.
                    'scale_users' => 'Utilizadores da balança',
                ],
                // Pôr em linha é o que atribui a pesagem seguinte, e sem ele a FC não é medida.
                'action' => [
                    'select_scale_user' => 'Pôr utilizador em linha',
                    'read_scale_history' => 'Ler o histórico',
                ],
            ],
            'settings_system' => [
                'setting' => [
                    'weight_unit' => 'Unidade do visor',
                ],
                'action' => [
                    'sync_configuration' => 'Sincronizar configuração',
                    'calibrate_clock' => 'Acertar o relógio do aparelho',
                    'restart_device' => 'Reiniciar dispositivo',
                ],
            ],
        ];
    }
}
