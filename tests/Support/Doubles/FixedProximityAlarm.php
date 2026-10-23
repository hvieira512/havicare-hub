<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Domain\ProximityAlarm;
use Hub\Domain\ProximityAlarmLookup;

/** O alarme de proximidade por gateway, que o teste muda a meio como a API faria. */
final class FixedProximityAlarm implements ProximityAlarmLookup
{
    /** @param array<string, array<string, mixed>> $byGateway */
    public function __construct(public array $byGateway = [])
    {
    }

    public function forGateway(string $gatewayKey): array
    {
        return ($this->byGateway[$gatewayKey] ?? []) + ProximityAlarm::defaults();
    }
}
