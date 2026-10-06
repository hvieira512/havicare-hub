<?php

declare(strict_types=1);

namespace Hub\Domain;

interface DiaperSensitivityLookup
{
    /**
     * A sensibilidade em vigor para um sensor; sem configuração, o preset normal. `forDevice`
     * porque `for` é palavra reservada.
     *
     * @return array{pollutionRange: int, pollutionValue: int}
     */
    public function forDevice(string $sensorKey): array;
}
