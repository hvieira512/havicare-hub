<?php

declare(strict_types=1);

namespace Hub\Ingress\Http\Qinglanst;

/**
 * O layout de um radar, do `thirdparty/v2/deviceProp`, reduzido a uma sala e caixas em decímetros.
 * O prefixo de cada nome em `declare_area_name` é o tipo da área, e não uma sequência.
 */
final class LayoutParser
{
    /**
     * @param array<string, mixed> $data o `data` da resposta do fabricante
     * @return array{
     *     room: array{x_min_dm: int, y_min_dm: int, x_max_dm: int, y_max_dm: int},
     *     areas: list<array<string, int|string>>,
     *     skipped: array<int, string>
     * }|null
     */
    public function parse(array $data): ?array
    {
        $room = $this->box($this->numbers(is_string($data['rectangle'] ?? null) ? $data['rectangle'] : ''));
        if ($room === null) {
            return null;
        }

        $names = $this->names($data['declare_area_name'] ?? null);
        $areas = [];
        $skipped = [];

        foreach ($this->areaTuples(is_string($data['declare_area'] ?? null) ? $data['declare_area'] : '') as [$key, $type, $points]) {
            $box = $this->box($points);
            if ($box === null) {
                $skipped[$key] = 'not_an_axis_aligned_box';
                continue;
            }

            $areas[] = ['key' => $key, 'type' => $type, 'name' => $names[$key] ?? "Área {$key}"] + $box;
        }

        return ['room' => $room, 'areas' => $areas, 'skipped' => $skipped];
    }

    /**
     * O separador é `;` ou `,` conforme a mensagem, e as chavetas não distinguem nada.
     *
     * @return list<int>
     */
    private function numbers(string $raw): array
    {
        $numbers = [];
        foreach (preg_split('/[;,]/', str_replace(['{', '}'], '', $raw)) ?: [] as $value) {
            $value = trim($value);
            if ($value !== '') {
                $numbers[] = (int)$value;
            }
        }

        return $numbers;
    }

    /**
     * O `declare_area` fecha com uma vírgula pendurada; o pedaço vazio cai por não ter chave e tipo.
     *
     * @return list<array{0: int, 1: int, 2: list<int>}>
     */
    private function areaTuples(string $raw): array
    {
        $tuples = [];
        foreach (explode('},', $raw) as $chunk) {
            $numbers = $this->numbers($chunk);
            if (count($numbers) < 3) {
                continue;
            }

            $key = (int)array_shift($numbers);
            $type = (int)array_shift($numbers);
            $tuples[] = [$key, $type, $numbers];
        }

        return $tuples;
    }

    /**
     * Caixas alinhadas aos eixos e não polígonos: duas coordenadas distintas em cada eixo,
     * independentemente de quantos pontos as descrevam.
     *
     * @param list<int> $points
     * @return array{x_min_dm: int, y_min_dm: int, x_max_dm: int, y_max_dm: int}|null
     */
    private function box(array $points): ?array
    {
        $total = count($points);
        if ($total < 8 || $total % 2 !== 0) {
            return null;
        }

        $xs = [];
        $ys = [];
        for ($index = 0; $index < $total; $index += 2) {
            $xs[$points[$index]] = true;
            $ys[$points[$index + 1]] = true;
        }

        if (count($xs) !== 2 || count($ys) !== 2) {
            return null;
        }

        $xs = array_keys($xs);
        $ys = array_keys($ys);

        return [
            'x_min_dm' => min($xs),
            'y_min_dm' => min($ys),
            'x_max_dm' => max($xs),
            'y_max_dm' => max($ys),
        ];
    }

    /**
     * Vem lista ou objeto conforme as chaves das áreas tenham buracos; liga-se pela chave e
     * nunca pela posição.
     *
     * @return array<int, string>
     */
    private function names(mixed $declared): array
    {
        if (!is_array($declared)) {
            return [];
        }

        $names = [];
        foreach ($declared as $key => $value) {
            $label = is_string($value) ? trim($value) : '';
            if ($label === '') {
                continue;
            }

            $parts = explode('_', $label);
            $names[(int)$key] = count($parts) > 1 ? implode('_', array_slice($parts, 1)) : $label;
        }

        return $names;
    }
}
