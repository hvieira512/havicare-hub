<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Domain\DiaperSensitivity;
use Hub\Domain\DiaperSensitivityLookup;

/** Uma sensibilidade que o teste muda a meio, como a API faria. */
final class MutableDiaperSensitivity implements DiaperSensitivityLookup
{
    /** @var array{pollutionRange: int, pollutionValue: int} */
    public array $settings;

    /** @param array{pollutionRange: int, pollutionValue: int}|null $settings */
    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? DiaperSensitivity::normal();
    }

    /** @return array{pollutionRange: int, pollutionValue: int} */
    public function forDevice(string $sensorKey): array
    {
        return $this->settings;
    }
}
