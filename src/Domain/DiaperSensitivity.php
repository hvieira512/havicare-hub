<?php

declare(strict_types=1);

namespace Hub\Domain;

/**
 * Os dois limiares que decidem quando uma fralda conta como suja, com os presets e as gamas
 * num só sítio.
 *
 * Não há downlink: o sensor é um beacon não-conectável, e o que estes valores mudam é a regra
 * com que o hub interpreta a mesma leitura física. Ver `docs/17-sensor-de-fralda.md` §3.
 */
final class DiaperSensitivity
{
    /**
     * Nomeados pela grandeza que se regula, como o `fall_sensitivity` dos relógios, e não
     * pela consequência. Do menos sensível para o mais, que é a ordem no ecrã.
     *
     * @var array<string, array{pollutionRange: int, pollutionValue: int}>
     */
    public const PRESETS = [
        'low' => ['pollutionRange' => 7, 'pollutionValue' => 15],
        'normal' => ['pollutionRange' => 4, 'pollutionValue' => 12],
        'high' => ['pollutionRange' => 3, 'pollutionValue' => 7],
    ];

    /** Gamas que a app deles aceita, em [mínimo, máximo]. */
    public const RANGE_BOUNDS = [2, 10];
    public const VALUE_BOUNDS = [5, 25];

    /**
     * A graduação, em [mínimo, máximo, etiqueta]. Viaja na resposta da API para o cliente não
     * manter uma segunda cópia destas fronteiras.
     *
     * @var list<array{int, int, string}>
     */
    public const RANGE_GRADES = [[2, 3, 'sensitive'], [4, 5, 'normal'], [6, 10, 'insensitive']];
    public const VALUE_GRADES = [[5, 8, 'sensitive'], [9, 16, 'normal'], [17, 25, 'insensitive']];

    /** @return array{pollutionRange: int, pollutionValue: int} */
    public static function normal(): array
    {
        return self::PRESETS['normal'];
    }

    /**
     * Derivado dos valores e nunca guardado: guardar os dois permitia que discordassem, e o
     * "personalizado" sai de graça em vez de ser um quarto estado.
     */
    public static function profile(int $pollutionRange, int $pollutionValue): string
    {
        foreach (self::PRESETS as $name => $preset) {
            if ($preset['pollutionRange'] === $pollutionRange && $preset['pollutionValue'] === $pollutionValue) {
                return $name;
            }
        }

        return 'custom';
    }

    /**
     * O limiar que separa `clean` de `attention`. É do hub e não da MONIT, e é derivado para
     * acompanhar o valor de molhado em vez de ficar absoluto.
     *
     * A divisão por 4 é o que mantém o índice de `clean` dentro da banda 0-25; o `+1` vem de
     * a comparação ser `<`. Ver `docs/17-sensor-de-fralda.md` §2.
     */
    public static function cleanMaxDelta(int $pollutionValue): int
    {
        return intdiv($pollutionValue, 4) + 1;
    }

    /** Devolve a mensagem de erro, ou null quando o par é aceitável. */
    public static function validate(int $pollutionRange, int $pollutionValue): ?string
    {
        [$rangeMin, $rangeMax] = self::RANGE_BOUNDS;
        if ($pollutionRange < $rangeMin || $pollutionRange > $rangeMax) {
            return "pollutionRange must be between {$rangeMin} and {$rangeMax}";
        }

        [$valueMin, $valueMax] = self::VALUE_BOUNDS;
        if ($pollutionValue < $valueMin || $pollutionValue > $valueMax) {
            return "pollutionValue must be between {$valueMin} and {$valueMax}";
        }

        return null;
    }

    /**
     * A etiqueta de graduação de um valor, para a API não obrigar o cliente a
     * reimplementar as fronteiras.
     *
     * @param list<array{int, int, string}> $grades
     */
    public static function grade(array $grades, int $value): string
    {
        foreach ($grades as [$minimum, $maximum, $label]) {
            if ($value >= $minimum && $value <= $maximum) {
                return $label;
            }
        }

        return 'custom';
    }
}
