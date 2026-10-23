<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Domain\DiaperSensitivityLookup;
use Hub\Domain\ProximityAlarmLookup;

/** O que o gateway faz com um avistamento. Sem `diaperSensitivity` vale o preset normal, e sem `proximityAlarm` não há alarme. */
final class MokoGatewayOptions
{
    public function __construct(
        public readonly int $dedupeTtlSeconds = 5,
        public readonly int $telemetryRefreshSeconds = 60,
        public readonly int $rawHistorySampleSeconds = 30,
        public readonly ?DiaperSensitivityLookup $diaperSensitivity = null,
        public readonly ?ProximityAlarmLookup $proximityAlarm = null,
    ) {
    }

    /** @param array<string, mixed> $gateway a secção `gateway` da configuração do hub */
    public static function fromConfig(
        array $gateway,
        ?DiaperSensitivityLookup $diaperSensitivity = null,
        ?ProximityAlarmLookup $proximityAlarm = null,
    ): self {
        return new self(
            dedupeTtlSeconds: (int)$gateway['dedupe_ttl_seconds'],
            telemetryRefreshSeconds: (int)$gateway['telemetry_refresh_seconds'],
            rawHistorySampleSeconds: (int)$gateway['raw_history_sample_seconds'],
            diaperSensitivity: $diaperSensitivity,
            proximityAlarm: $proximityAlarm,
        );
    }
}
