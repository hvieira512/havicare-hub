<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

use Hub\Domain\DiaperSensitivity;

/**
 * O que se configura num medidor de fraldas da MONIT: só a sensibilidade, que o hub aplica
 * (`HubAppliedCapability`) porque o sensor só transmite — daí o `command` vazio.
 */
final class MonitConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            ConfigurationDefinition::make(
                'diaper_sensitivity',
                '',
                'Sensibilidade dos alertas',
                'diaperSensitivity',
                ['pollutionRange', 'pollutionValue'],
                [],
                'alerts',
                10,
                null,
                [
                    'profile' => array_map(
                        static fn(string $name): array => ['value' => $name, 'label' => $name],
                        array_keys(DiaperSensitivity::PRESETS),
                    ),
                ],
                help: 'Alta avisa com menos humidade; Baixa espera por mais.',
            ),
        ];
    }
}
