<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Domain\DiaperSensitivityLookup;

/**
 * O que o gateway faz com um avistamento: as janelas que o afinam e o que consulta quando
 * está ligado.
 *
 * Os quatro números são afinação de hardware e ficam onde se possam mexer sem tocar no
 * bridge; os dois opcionais só existem quando quem monta o hub os ligou -- sem eles, não há
 * proximidade nem sensibilidade de fralda, e o resto continua a correr.
 */
final class MokoGatewayOptions
{
    public function __construct(
        public readonly int $dedupeTtlSeconds = 5,
        public readonly int $telemetryRefreshSeconds = 60,
        public readonly int $gatewayIdleTimeoutSeconds = 180,
        public readonly int $rawHistorySampleSeconds = 30,
        public readonly ?ProximityTracker $proximityTracker = null,
        public readonly ?DiaperSensitivityLookup $diaperSensitivity = null,
    ) {
    }

    /** @param array<string, mixed> $gateway a secção `gateway` da configuração do hub */
    public static function fromConfig(array $gateway, ?DiaperSensitivityLookup $diaperSensitivity = null): self
    {
        return new self(
            dedupeTtlSeconds: (int)$gateway['dedupe_ttl_seconds'],
            telemetryRefreshSeconds: (int)$gateway['telemetry_refresh_seconds'],
            gatewayIdleTimeoutSeconds: (int)$gateway['idle_timeout_seconds'],
            rawHistorySampleSeconds: (int)$gateway['raw_history_sample_seconds'],
            diaperSensitivity: $diaperSensitivity,
        );
    }
}
