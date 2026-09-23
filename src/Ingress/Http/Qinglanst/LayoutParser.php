<?php

namespace Hub\Ingress\Http\Qinglanst;

/**
 * O layout de um radar, como o `thirdparty/v2/deviceProp` do fabricante o devolve.
 *
 * Três armadilhas, e as três aparecem juntas na mesma resposta: o `declare_area` fecha com uma
 * vírgula pendurada, o `declare_area_name` vem lista ou objeto conforme as chaves das áreas
 * sejam seguidas ou tenham buracos, e o prefixo de cada nome é o tipo da área -- que já vem no
 * `declare_area` -- e não uma sequência.
 *
 * Sai daqui uma sala e um punhado de caixas em decímetros: nenhuma string do fabricante
 * sobrevive à fronteira.
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
     * Os pares `{x,y;x,y}` e `{chave,tipo,x,y,...}` separam com `;` ou com `,` conforme a
     * mensagem, e as chavetas não distinguem nada.
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
     * A vírgula pendurada no fim deixa um pedaço vazio, que cai aqui por não ter sequer chave
     * e tipo.
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
            $tuples[] = [$key, $type, array_values($numbers)];
        }

        return $tuples;
    }

    /**
     * Guardamos caixas e não polígonos: as 63 áreas dos 13 radares em produção são todas
     * caixas alinhadas aos eixos. Duas coordenadas distintas em cada eixo é o que define uma,
     * independentemente de quantos pontos a descrevam.
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
     * Lista e objeto indexam-se da mesma maneira: as chaves numéricas do objeto viram inteiros
     * ao descodificar, e numa lista seguida o índice coincide com a chave da área. O que não
     * pode acontecer é ligar pela posição -- com buracos nas chaves, a área 5 ficaria com o
     * nome da 3.
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
