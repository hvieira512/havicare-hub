<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

use Hub\Domain\ProximityAlarm;

/** O que se configura num gateway MOKO: só o alarme de proximidade, que o hub aplica. */
final class MokoGatewayConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            ConfigurationDefinition::make(
                'proximity_alert',
                '',
                'proximityAlarm',
                ['mode', 'devices', 'triggerDbm', 'minSamples', 'cooldownSeconds'],
                category: 'alerts',
                order: 10,
                options: [
                    'mode' => array_map(static fn(string $mode): array => ['value' => $mode], ProximityAlarm::MODES),
                ],
            ),
        ];
    }
}
