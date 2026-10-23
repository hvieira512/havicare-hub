<?php

declare(strict_types=1);

namespace Hub\Domain;

interface ProximityAlarmLookup
{
    /**
     * O alarme de proximidade guardado para o gateway, ou o desligado por omissão.
     *
     * @return array<string, mixed>
     */
    public function forGateway(string $gatewayKey): array;
}
