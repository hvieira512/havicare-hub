<?php

namespace Hub\Domain\Capability\AlarmClock;

/**
 * Ajudantes partilhados pelos handlers do `alarm_clock`.
 */
trait AlarmClockHelpers
{
    /**
     * Dois despertadores concorrentes acumulam-se em vez de um substituir o outro: a lista é
     * o valor, e chega uma de cada vez.
     */
    public function merge(mixed $existing, mixed $incoming): mixed
    {
        $existingList = is_array($existing) ? array_values($existing) : [];
        $incomingList = is_array($incoming) ? array_values($incoming) : [];

        return array_values(array_merge($existingList, $incomingList));
    }

    /**
     * A lista pública de alarmes a partir do payload do protocolo.
     *
     * O `$keys` é a ordem de precedência, que difere por fornecedor, e o fim da linha é o
     * próprio `$desired` -- o apresentador chama isto duas vezes na mesma leitura, e à segunda
     * o que chega já é a lista pública, sem chave à volta.
     *
     * @param array<string, mixed> $desired
     * @param list<string> $keys
     * @param callable(mixed): array<string, mixed> $asPublicItem
     * @return list<array<string, mixed>>
     */
    public static function publicItemList(array $desired, array $keys, callable $asPublicItem): array
    {
        $items = $desired;
        foreach ($keys as $key) {
            if (isset($desired[$key])) {
                $items = $desired[$key];
                break;
            }
        }

        if (!is_array($items)) {
            return [];
        }
        if (!array_is_list($items)) {
            $items = [$items];
        }
        // Um item que já traz `recurrence` é público, e sai como entrou.
        if ($items !== [] && is_array($items[0] ?? null) && array_key_exists('recurrence', $items[0])) {
            return array_values($items);
        }

        return array_values(array_filter(
            array_map($asPublicItem, $items),
            static fn (array $item): bool => $item !== [],
        ));
    }

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
