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
        return [
            ConfigurationDefinition::make(
                'heart_rate_measurement',
                'heartRateMeasurement',
                'Medir a frequência cardíaca',
                'toggle',
                ['enabled'],
                ['torre_06_set'],
                'health',
                10,
                help: 'Só é medida com um utilizador em linha.',
            ),
            ConfigurationDefinition::make(
                'impedance_measurement',
                'impedanceMeasurement',
                'Medir a impedância',
                'toggle',
                ['enabled'],
                ['torre_07_set'],
                'health',
                20,
                help: 'Desligar na gravidez: a balança passa a só pesar.',
            ),
            ConfigurationDefinition::make(
                'scale_users',
                'scaleUsers',
                'Utilizadores da balança',
                'scaleUsers',
                ['items'],
                // A lista entrega-se em registos e remoções: os dois comandos vêm declarados aqui.
                ['torre_user_02', 'torre_user_04', 'torre_user_07', 'registerScaleUser', 'deleteScaleUser', 'selectScaleUser'],
                'health',
                30,
                help: 'Quem se pesa nesta balança. O utilizador em linha fica com a pesagem seguinte, e é o que deixa medir a FC.',
            ),
            ConfigurationDefinition::make(
                'select_scale_user',
                'selectScaleUser',
                'Pôr utilizador em linha',
                'scaleUserAction',
                ['id'],
                ['torre_user_07'],
                'health',
                40,
                transient: true,
            ),
            ConfigurationDefinition::make(
                'read_scale_history',
                'readScaleHistory',
                'Ler o histórico',
                'scaleUserAction',
                ['id'],
                ['torre_history_0a'],
                'health',
                50,
                transient: true,
                help: 'As pesagens que a balança guardou deste utilizador; chegam com a data em que foram feitas.',
            ),
            ConfigurationDefinition::make(
                'weight_unit',
                'weightUnit',
                'Unidade do visor',
                'select',
                ['unit'],
                ['torre_05_set'],
                'system',
                10,
                options: ['unit' => [
                    ['value' => 'kg', 'label' => 'Quilogramas'],
                    ['value' => 'lb', 'label' => 'Libras'],
                    ['value' => 'jin', 'label' => 'Jin'],
                    ['value' => 'st', 'label' => 'Stones'],
                    ['value' => 'st_lb', 'label' => 'Stones e libras'],
                ], 'default' => 'kg'],
                help: 'Só muda o visor: o peso é publicado sempre em quilogramas.',
            ),
            ConfigurationDefinition::make(
                'sync_configuration',
                'syncConfiguration',
                'Sincronizar configuração',
                'action',
                [],
                ['torre_05_read'],
                'system',
                20,
                transient: true,
                help: 'Relê a unidade e os dois interruptores; não escreve nada.',
            ),
            ConfigurationDefinition::make(
                'calibrate_clock',
                'calibrateClock',
                'Acertar o relógio do aparelho',
                'action',
                [],
                ['torre_04_set'],
                'system',
                30,
                transient: true,
                help: 'O hub também o acerta a cada ligação: o histórico é datado por ele.',
            ),
            ConfigurationDefinition::make(
                'restart_device',
                'restartDevice',
                'Reiniciar',
                'action',
                [],
                [],
                'system',
                40,
                transient: true,
                confirm: 'Reiniciar a balança?',
            ),
        ];
    }
}
