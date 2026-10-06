<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\AlarmClock\AlarmClockHelpers;

/**
 * A forma pública de um plano de medicação, partilhada pelos três fornecedores.
 *
 * Um plano é um medicamento com as suas horas. Quem não sabe o nome, a dose ou as datas
 * omite-os, como em todo o contrato: uma chave vazia obriga quem lê a distinguir o vazio do
 * ausente.
 */
final class MedicationPlanShape
{
    use AlarmClockHelpers;

    /** As horas do dia que a Wonlex nomeia, pela ordem do índice que ela usa nos períodos. */
    public const WONLEX_PERIODS = [0 => 'Morning', 1 => 'Midday', 2 => 'Night', 3 => 'Before sleep'];

    /** Os mesmos quatro períodos, com o nome que sai no contrato. */
    public const PERIODS = [0 => 'morning', 1 => 'midday', 2 => 'night', 3 => 'before_sleep'];

    /** `0800`, `8:00` ou `08:00` passam todos a `08:00`; o que não é hora sai vazio. */
    public static function time(string $value): string
    {
        $digits = preg_replace('/\D/', '', trim($value)) ?? '';
        if (strlen($digits) < 3 || strlen($digits) > 4) {
            return '';
        }

        $digits = str_pad($digits, 4, '0', STR_PAD_LEFT);
        $hour = (int)substr($digits, 0, 2);
        $minute = (int)substr($digits, 2, 2);

        return $hour > 23 || $minute > 59
            ? ''
            : sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Uma hora do plano, com o que o fornecedor lhe acrescenta: o `slot` é o compartimento do
     * dispensador e o `period` é a altura do dia que a Wonlex nomeia. Nenhum se adivinha.
     *
     * @param array<string, mixed> $recurrence
     * @param array<string, mixed> $extras
     * @return array<string, mixed>
     */
    public static function timeEntry(string $time, bool $enabled, array $recurrence, array $extras = []): array
    {
        return ['time' => self::time($time), 'enabled' => $enabled]
            + array_filter($extras, static fn(mixed $value): bool => $value !== null)
            + ['recurrence' => $recurrence];
    }

    /**
     * @param array<string, mixed> $core as chaves do medicamento, com `null` no que se não sabe
     * @param list<array<string, mixed>> $times
     * @return array<string, mixed>
     */
    public static function plan(array $core, array $times): array
    {
        return array_filter($core, static fn(mixed $value): bool => $value !== null && $value !== '')
            + ['times' => $times];
    }

    /** A condição que o medicamento trata, como a Wonlex a numera. */
    public const CONDITIONS = [0 => 'hypertension', 1 => 'diabetes', 2 => 'cholesterol', 3 => 'uric_acid'];

    /** A unidade da dose, como a Wonlex a numera. */
    public const DOSE_UNITS = ['0' => 'tablet', '1' => 'ampoule', '2' => 'ml', '3' => 'mg', '4' => 'iu', '5' => 'other'];

    public const MEAL_TIMINGS = [0 => 'before_meal', 1 => 'after_meal'];

    /**
     * O nome inglês de um código nativo, ou `null` se o código não for conhecido -- que é o
     * mesmo que não saber, e por isso omite-se.
     *
     * @param array<array-key, string> $table
     */
    public static function nameOf(array $table, mixed $code): ?string
    {
        return $table[is_int($code) ? $code : (string)$code] ?? null;
    }

    /**
     * O código nativo de um nome inglês.
     *
     * @param array<array-key, string> $table
     */
    public static function codeOf(array $table, mixed $name, int|string $fallback): int|string
    {
        $found = array_search((string)$name, $table, true);

        return $found === false ? $fallback : $found;
    }

    /** O nativo manda `1`/`0` e `"1"`/`"0"` tanto como booleanos. */
    public static function enabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }

        return !in_array(strtolower(trim((string)$value)), ['', '0', 'false', 'off', 'no'], true);
    }

    /**
     * O `kind` da recorrência a partir do modo nativo que o 4P Touch partilha com os alarmes.
     *
     * @return array{kind: string, days?: list<int>}
     */
    public static function recurrenceFromMode(int $mode, string $mask): array
    {
        if ($mode === 2) {
            return ['kind' => 'daily'];
        }
        if ($mode !== 3) {
            return ['kind' => 'once'];
        }

        // A máscara do 4P Touch começa ao domingo: a posição 0 é o dia 7.
        $days = self::parseDayMaskToList($mask);

        return $days === [] ? ['kind' => 'custom'] : ['kind' => 'custom', 'days' => $days];
    }

    /**
     * O inverso do `recurrenceFromMode`: o modo e a máscara de dias que o 4P Touch grava.
     *
     * @param array<string, mixed> $recurrence
     * @return array{0: int, 1: string}
     */
    public static function modeFromRecurrence(array $recurrence): array
    {
        $kind = (string)($recurrence['kind'] ?? 'once');
        if ($kind === 'daily') {
            return [2, ''];
        }
        if ($kind !== 'custom') {
            return [1, ''];
        }

        $mask = array_fill(0, 7, '0');
        foreach (is_array($recurrence['days'] ?? null) ? $recurrence['days'] : [] as $day) {
            $value = (int)$day;
            if ($value === 7) {
                $mask[0] = '1';
            } elseif ($value >= 1 && $value <= 6) {
                $mask[$value] = '1';
            }
        }

        return [3, implode('', $mask)];
    }
}
