<?php

declare(strict_types=1);

namespace Hub\Domain;

interface DiaperSensitivityLookup
{
    /**
     * A sensibilidade em vigor para um sensor.
     *
     * Devolve sempre um par utilizável: um sensor sem configuração recebe o preset
     * normal, que é o comportamento com que o hub sempre correu. Chama-se
     * `forDevice` e não `for` porque `for` é palavra reservada em PHP.
     *
     * @return array{pollutionRange: int, pollutionValue: int}
     */
    public function forDevice(string $sensorKey): array;
}
