<?php

namespace Hub\Domain\Capability\AlarmClock;

/**
 * Ajudantes partilhados pelos handlers do `alarm_clock`.
 */
trait AlarmClockHelpers
{
    public static function boolLikeToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $value == 1 || $value === '1';
    }

    /** @return list<int> */
    public static function parseDayList(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $days = [];
        foreach (str_split($value) as $char) {
            $day = (int)$char;
            if ($day >= 1 && $day <= 7) {
                $days[$day] = true;
            }
        }

        $result = array_keys($days);
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /** @return list<int> */
    public static function parseDayMaskToList(string $mask): array
    {
        $mask = trim($mask);
        if ($mask === '' || !preg_match('/^[01]{7}$/', $mask)) {
            return [];
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            if ($mask[$i] === '1') {
                $days[] = $i === 0 ? 7 : $i;
            }
        }

        return $days;
    }

    /** @param list<int> $days */
    public static function formatDayList(array $days): string
    {
        $normalized = [];
        foreach ($days as $day) {
            $value = (int)$day;
            if ($value >= 1 && $value <= 7) {
                $normalized[$value] = true;
            }
        }

        $ordered = array_keys($normalized);
        sort($ordered, SORT_NUMERIC);

        return implode('', array_map('strval', $ordered));
    }
}
