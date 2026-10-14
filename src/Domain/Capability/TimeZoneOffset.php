<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

use DateTimeImmutable;
use DateTimeZone;

/** O desvio de uma zona com nome num instante, no formato dos aparelhos: HHMM com sinal. */
final class TimeZoneOffset
{
    public static function hhmm(string $zone, DateTimeImmutable $at): int
    {
        try {
            $timeZone = new DateTimeZone($zone);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Unknown time zone {$zone}");
        }
        $minutes = intdiv($timeZone->getOffset($at), 60);

        return intdiv($minutes, 60) * 100 + $minutes % 60;
    }
}
