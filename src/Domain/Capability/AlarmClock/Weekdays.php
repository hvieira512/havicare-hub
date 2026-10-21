<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\AlarmClock;

/**
 * Os dias de 1 (segunda) a 7 (domingo) e as máscaras de sete posições dos fornecedores: o 4P
 * Touch põe o domingo à frente, a Wonlex põe-no no fim.
 */
final class Weekdays
{
    /**
     * A máscara de uma lista de dias, de uma máscara já feita ou dos dígitos `1`–`7`; `null` se não
     * for nenhuma delas.
     */
    public static function mask(mixed $value, bool $sundayFirst): ?string
    {
        if (is_array($value)) {
            return $value === [] ? null : self::fromDays($value, $sundayFirst);
        }

        $value = trim((string)$value);
        if (preg_match('/^[01]{7}$/', $value) === 1) {
            return $value;
        }

        return preg_match('/^[1-7]+$/', $value) === 1 ? self::fromDays(str_split($value), $sundayFirst) : null;
    }

    /** @return list<int> os dias de uma máscara, pela ordem das posições; vazio para o que não é máscara */
    public static function days(string $mask, bool $sundayFirst): array
    {
        if (preg_match('/^[01]{7}$/', $mask) !== 1) {
            return [];
        }

        $days = [];
        foreach (str_split($mask) as $position => $bit) {
            if ($bit === '1') {
                $days[] = $sundayFirst ? ($position === 0 ? 7 : $position) : $position + 1;
            }
        }

        return $days;
    }

    /** @param array<array-key, mixed> $days */
    private static function fromDays(array $days, bool $sundayFirst): string
    {
        $mask = array_fill(0, 7, '0');
        foreach ($days as $day) {
            $day = (int)$day;
            if ($day >= 1 && $day <= 7) {
                $mask[$sundayFirst ? $day % 7 : $day - 1] = '1';
            }
        }

        return implode('', $mask);
    }
}
