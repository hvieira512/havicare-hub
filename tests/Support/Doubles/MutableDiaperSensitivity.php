<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Domain\DiaperSensitivity;
use Hub\Domain\DiaperSensitivityLookup;

/**
 * Um lookup de sensibilidade cujo valor se muda a meio de um teste, como a API faria: classe
 * com nome para o `$settings` ficar à vista, o que uma anónima devolvida como interface esconde.
 */
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
