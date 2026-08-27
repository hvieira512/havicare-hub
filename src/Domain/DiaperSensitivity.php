<?php

declare(strict_types=1);

namespace Hub\Domain;

/**
 * Os dois limiares que decidem quando uma fralda conta como suja.
 *
 * A app da MONIT expõe exactamente estes dois valores e três presets sobre eles.
 * Isto existe para que os presets, as gamas válidas e a graduação vivam num só
 * ficheiro em vez de espalhados pelo repositório, pelo controlador, pelo
 * normalizador e pelo JavaScript.
 *
 * NÃO HÁ DOWNLINK. O sensor é um beacon BLE não-conectável e nada lhe é enviado; o
 * que estes valores mudam é a regra com que o hub deriva o estado da fralda a
 * partir da mesma leitura física.
 */
final class DiaperSensitivity
{
    /**
     * Os três presets da app da MONIT, nomeados pela sensibilidade que representam.
     *
     * A app deles chama-lhes "More/Normal/Fewer Diaper Alerts", pela consequência. O
     * hub nomeia-os pela grandeza que se está a regular, como o `fall_sensitivity` dos
     * relógios: baixa sensibilidade é que dá menos alertas, e ter as chaves a falar de
     * contagem de alertas obrigava a inverter o eixo para as ler.
     *
     * Ordenados do menos sensível para o mais, que é a ordem em que aparecem no ecrã.
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
     * A graduação que a app deles mostra ao lado de um valor personalizado, em
     * [mínimo, máximo, etiqueta]. Viaja na resposta da API para que quem desenha o
     * selector não tenha de manter uma segunda cópia destas fronteiras.
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
     * O nome do perfil é derivado dos valores e nunca guardado.
     *
     * Guardar perfil E valores permitia que discordassem -- um perfil "normal" com
     * os valores do "high". Com uma só fonte de verdade isso é impossível, e
     * o "personalizado" sai de graça em vez de ser um quarto estado a manter.
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
     * O limiar que separa `clean` de `attention`, derivado do valor de molhado.
     *
     * Este terceiro limiar é NOSSO e não da MONIT: a app deles expõe dois valores e
     * tem dois estados, e o `clean` é invenção do hub. Mantê-lo absoluto enquanto o
     * valor de molhado varia produzia absurdos -- com `pollutionValue` a 25, um delta
     * de 4 tirava a fralda de `clean` faltando-lhe 21 para contar como molhada.
     *
     * A divisão por 4 não é arbitrária: é o que mantém verdadeira a prova aritmética
     * das bandas do índice de humidade. Com todos os deltas em `clean` limitados a
     * `pollutionValue / 4`, cada termo da saturação fica em 0.25 ou abaixo, logo a
     * média também, logo o índice não passa de 25 -- que é o tecto da banda `clean`.
     * O `+1` está aqui porque a comparação é `< cleanMaxDelta` e não `<=`, e é ele
     * que dá o 4 do preset normal.
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
