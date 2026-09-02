<?php

declare(strict_types=1);

namespace Hub\Api\Http;

/**
 * As listagens que cabem em memória: filtra, ordena, conta e só depois pagina.
 *
 * A ordem dos quatro passos não é indiferente. Contar antes de filtrar daria números que não
 * correspondem ao que se vê; paginar antes de ordenar deixaria a página 2 com as linhas da
 * ordem anterior.
 *
 * As listagens grandes -- a de dispositivos -- não passam por aqui: essas paginam no SQL,
 * porque trazê-las inteiras para memória a cada pedido não escala.
 */
final class CollectionPresenter
{
    public function __construct(
        private CollectionQuery $query = new CollectionQuery(),
        private CollectionResponder $responder = new CollectionResponder(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function present(array $items, CollectionColumns $columns, array $params, int $defaultLimit = 20): array
    {
        [$filtered, $applied] = $this->filter($items, $columns, $params);
        $sorted = $this->sort($filtered, $columns, $params);

        // Cada faceta conta-se sem o seu próprio filtro: escolher um fornecedor estreita os
        // modelos, mas os outros fornecedores continuam à escolha. Contá-la depois de tudo
        // deixava quem escolheu preso na escolha que fez.
        [$available, $counts] = $this->facets($items, $columns, $params);

        $response = $this->responder->respond(
            $sorted,
            $this->query->page($params),
            $this->query->limit($params, $defaultLimit),
            $applied,
            $available,
        );
        $response['filters']['counts'] = $counts;
        $response['columns'] = $columns->describe($counts);

        return $response;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{list<array<string, mixed>>, array<string, mixed>}
     */
    private function filter(array $items, CollectionColumns $columns, array $params): array
    {
        $applied = [];
        foreach (array_keys($columns->textFilterColumns()) as $field) {
            $needle = trim((string)($params[$field] ?? ''));
            if ($needle === '') {
                continue;
            }
            $applied[$field] = $needle;
            $items = array_values(array_filter(
                $items,
                static fn(array $row): bool => stripos((string)($row[$field] ?? ''), $needle) !== false,
            ));
        }

        foreach (array_keys($columns->fixedOptionFields()) as $field) {
            $chosen = trim((string)($params[$field] ?? ''));
            if ($chosen === '') {
                continue;
            }
            $applied[$field] = $chosen;
            $items = array_values(array_filter(
                $items,
                static fn(array $row): bool => strcasecmp(self::text($row[$field] ?? ''), $chosen) === 0,
            ));
        }

        return [$items, $applied];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function sort(array $items, CollectionColumns $columns, array $params): array
    {
        if (($params['sort'] ?? null) === null) {
            return $items;
        }

        $order = $this->query->sort($params, array_keys($columns->sortableColumns()), '');
        $order = array_values(array_filter($order, static fn(array $entry): bool => $entry['column'] !== ''));
        if ($order === []) {
            return $items;
        }

        usort($items, static function (array $left, array $right) use ($order): int {
            foreach ($order as $entry) {
                $comparison = self::compare($left[$entry['column']] ?? null, $right[$entry['column']] ?? null);
                if ($comparison !== 0) {
                    return $entry['descending'] ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $items;
    }

    /** Números por valor e texto como português. A falta de valor vai para o fim. */
    private static function compare(mixed $left, mixed $right): int
    {
        $leftMissing = $left === null || $left === '';
        $rightMissing = $right === null || $right === '';
        if ($leftMissing || $rightMissing) {
            return $leftMissing && $rightMissing ? 0 : ($leftMissing ? 1 : -1);
        }

        if (is_numeric($left) && is_numeric($right)) {
            return (float)$left <=> (float)$right;
        }

        return strnatcasecmp(self::text($left), self::text($right));
    }

    /**
     * Os valores possíveis de cada coluna de escolha, com a contagem de cada um.
     *
     * @param list<array<string, mixed>> $items
     * @return array{array<string, list<string>>, array<string, list<array{value: string, count: int}>>}
     */
    private function facets(array $items, CollectionColumns $columns, array $params): array
    {
        $available = [];
        $counts = [];

        foreach (array_keys($columns->fixedOptionFields()) as $field) {
            [$others] = $this->filter($items, $columns, self::without($params, $field));

            $tally = [];
            foreach ($others as $row) {
                $value = self::text($row[$field] ?? '');
                if ($value === '') {
                    continue;
                }
                $tally[$value] = ($tally[$value] ?? 0) + 1;
            }

            uksort($tally, static fn(string $left, string $right): int => strnatcasecmp($left, $right));
            $available[$field] = array_keys($tally);
            $counts[$field] = array_map(
                static fn(string $value): array => ['value' => $value, 'count' => $tally[$value]],
                array_keys($tally),
            );
        }

        return [$available, $counts];
    }

    /**
     * Os mesmos parâmetros sem o filtro de uma coluna, para a faceta dela se contar como se
     * ninguém a tivesse escolhido.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function without(array $params, string $field): array
    {
        unset($params[$field]);

        return $params;
    }

    /** Um booleano guardado como `1`/`0` lê-se como `true`/`false`, que é o que se filtra. */
    private static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return trim((string)$value);
    }
}
