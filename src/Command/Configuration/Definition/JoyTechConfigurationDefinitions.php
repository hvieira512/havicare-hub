<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

/**
 * O que se pede a um tensiómetro JoyTech. O `command` é a operação que o hub converte em escritas
 * GATT; o relógio não tem botão, porque se acerta sozinho a cada ligação.
 */
final class JoyTechConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            ConfigurationDefinition::make(
                'read_blood_pressure_history',
                'readBloodPressureHistory',
                'Ler a memória',
                'action',
                [],
                // Fecha-o o gateway, que espera pelo `0x8B` desta leitura: o de outra não conta.
                [],
                'health',
                10,
                transient: true,
                help: 'As medições que o aparelho guardou; as que o hub já tem não se repetem. Também se lê a cada ligação.',
                verb: 'Ler',
            ),
        ];
    }
}
