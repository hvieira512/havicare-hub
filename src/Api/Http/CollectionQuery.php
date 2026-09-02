<?php

namespace Hub\Api\Http;

final class CollectionQuery
{
    public function params(string $query): array
    {
        if ($query === '') {
            return [];
        }

        parse_str($query, $params);

        return is_array($params) ? $params : [];
    }

    public function page(array $params): int
    {
        return max(1, (int)($params['page'] ?? 1));
    }

    public function limit(array $params, int $default): int
    {
        return max(1, (int)($params['limit'] ?? $default));
    }

    public function filter(array $params, string $key, ?string $default = null): ?string
    {
        $value = trim((string)($params[$key] ?? ''));

        return $value === '' ? $default : $value;
    }

    /**
     * Um filtro que aceita vários valores.
     *
     * Lê tanto `?supplier[]=a&supplier[]=b` como `?supplier=a,b`, porque a primeira forma é
     * o que um cliente que constrói a query com um array produz naturalmente e a segunda é
     * o que se escreve à mão. "all" continua a querer dizer "sem filtro", como no filtro de
     * valor único, para que um cliente antigo não passe a filtrar pela palavra "all".
     *
     * @return list<string>
     */
    public function filterList(array $params, string $key): array
    {
        $raw = $params[$key] ?? null;
        if ($raw === null) {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string)$raw);
        $clean = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value === '' || $value === 'all') {
                continue;
            }
            $clean[] = $value;
        }

        return array_values(array_unique($clean));
    }

    /**
     * As colunas por que se ordena e o sentido de cada uma, separadas por vírgula e pela
     * ordem em que foram escritas -- `-company,model` ordena por empresa descendente e
     * desempata por modelo. O `-` à frente pede descendente.
     *
     * O valor acaba num `ORDER BY`, onde não pode entrar como parâmetro ligado. A allowlist
     * é por isso a fronteira, e o que não estiver nela não é limpo -- cai fora. Uma coluna
     * má no meio de boas leva só a si própria, para um engano não deitar o resto abaixo.
     *
     * @param array<string, mixed> $params
     * @param list<string> $allowed
     * @return non-empty-list<array{column: string, descending: bool}>
     */
    public function sort(array $params, array $allowed, string $default): array
    {
        $fallback = [['column' => $default, 'descending' => false]];
        $raw = $params['sort'] ?? null;
        if (!is_string($raw)) {
            return $fallback;
        }

        $resolved = [];
        $seen = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            $descending = str_starts_with($piece, '-');
            $wanted = strtolower(ltrim($piece, '-'));

            foreach ($allowed as $column) {
                if (strtolower($column) !== $wanted || isset($seen[$column])) {
                    continue;
                }
                $seen[$column] = true;
                $resolved[] = ['column' => $column, 'descending' => $descending];
                break;
            }
        }

        return $resolved === [] ? $fallback : $resolved;
    }

    /**
     * O estado de ligação: `online`, `offline`, ou nada quando não se filtra.
     *
     * É o único filtro de valor único desta listagem, porque escolher os dois é o mesmo que
     * não escolher nenhum -- e isso já é a ausência do parâmetro.
     */
    public function onlineFilter(array $params): ?bool
    {
        $value = strtolower(trim((string)($params['online'] ?? '')));

        return match ($value) {
            'online', '1', 'true' => true,
            'offline', '0', 'false' => false,
            default => null,
        };
    }
}
