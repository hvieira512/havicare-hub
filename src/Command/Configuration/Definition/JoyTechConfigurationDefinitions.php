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
                'action',
                [],
                // Sem resposta esperada: fecha-o o gateway, que espera pelo `0x8B` desta leitura.
                category: 'health',
                order: 10,
                transient: true,
                verb: 'read',
            ),
        ];
    }
}
