<?php

namespace Hub\Command\Configuration\Definition;

use Hub\Domain\DiaperSensitivity;

/**
 * O que se configura num medidor de fraldas da MONIT.
 *
 * Uma entrada só, e sem `command`: o sensor é um beacon BLE que só transmite, e a
 * sensibilidade é aplicada pelo hub -- é a `DiaperSensitivityCapability`, marcada com
 * `HubAppliedCapability`, que diz que esta configuração não viaja. O `command` vazio é o
 * que se lê disso no catálogo.
 *
 * Existe para o painel de configuração ter o que desenhar: é daqui que sai o tipo de
 * campo, e os presets e as gamas vêm no `_meta` da capacidade.
 */
final class MonitConfigurationDefinitions
{
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
            ),
        ];
    }
}
