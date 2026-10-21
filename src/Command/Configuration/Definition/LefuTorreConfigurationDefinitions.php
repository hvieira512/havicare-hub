<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

/**
 * O que se configura numa balança Torre. O `command` é a operação que o hub converte em escritas
 * GATT; o gateway só as faz. As respostas são as tramas `<cmd> 02 01 <resultado>` do `FFF1`.
 */
final class LefuTorreConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);

        return [
            $entry('heart_rate_measurement', 'heartRateMeasurement', 'toggle', ['enabled'], ['torre_06_set'], category: 'health', order: 10),
            $entry('impedance_measurement', 'impedanceMeasurement', 'toggle', ['enabled'], ['torre_07_set'], category: 'health', order: 20),
            // A lista entrega-se em registos e remoções: os dois comandos vêm declarados aqui.
            $entry('scale_users', 'scaleUsers', 'scaleUsers', ['items'], [
                'torre_user_02', 'torre_user_04', 'torre_user_07', 'registerScaleUser', 'deleteScaleUser', 'selectScaleUser',
            ], category: 'health', order: 30, bounds: ['heightCm' => ['min' => 50, 'max' => 250], 'weightKg' => ['min' => 0, 'max' => 250]]),
            $entry('select_scale_user', 'selectScaleUser', 'scaleUserAction', ['id'], ['torre_user_07'], category: 'health', order: 40, transient: true),
            $entry('read_scale_history', 'readScaleHistory', 'scaleUserChoice', ['id'], ['torre_history_0a'], category: 'health', order: 50, transient: true),
            $entry('weight_unit', 'weightUnit', 'select', ['unit'], ['torre_05_set'], category: 'system', order: 10, options: [
                'unit' => [['value' => 'kg'], ['value' => 'lb'], ['value' => 'jin'], ['value' => 'st'], ['value' => 'st_lb']],
                'default' => 'kg',
            ]),
            $entry('sync_configuration', 'syncConfiguration', 'action', [], ['torre_05_read'], category: 'system', order: 20, transient: true),
            $entry('calibrate_clock', 'calibrateClock', 'action', [], ['torre_04_set'], category: 'system', order: 30, transient: true),
            $entry('restart_device', 'restartDevice', 'action', [], category: 'system', order: 40, transient: true, destructive: true),
        ];
    }
}
