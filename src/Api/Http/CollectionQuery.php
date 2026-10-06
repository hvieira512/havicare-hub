<?php

declare(strict_types=1);

namespace Hub\Api\Http;

final class CollectionQuery
{
    /** @return array<string, mixed> */
    public function params(string $query): array
    {
        if ($query === '') {
            return [];
        }

        parse_str($query, $params);

        return is_array($params) ? $params : [];
    }

    /** @param array<string, mixed> $params */
    public function page(array $params): int
    {
        return max(1, (int)($params['page'] ?? 1));
    }

    /** @param array<string, mixed> $params */
    public function limit(array $params, int $default): int
    {
        return max(1, (int)($params['limit'] ?? $default));
    }

    /** @param array<string, mixed> $params */
    public function filter(array $params, string $key, ?string $default = null): ?string
    {
        $value = trim((string)($params[$key] ?? ''));

        return $value === '' ? $default : $value;
    }

    /**
     * Um filtro que aceita vários valores, em `?supplier[]=a&supplier[]=b` ou `?supplier=a,b`.
     * "all" quer dizer "sem filtro", como no filtro de valor único.
     *
     * @param array<string, mixed> $params
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
     * As colunas e o sentido, pela ordem em que mandam: `company:desc,model:asc`; sem sentido é
     * ascendente. Acaba num `ORDER BY` sem parâmetro ligado, e o que não estiver na allowlist cai fora.
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
            [$wanted, $direction] = array_pad(explode(':', trim($piece), 2), 2, 'asc');
            $wanted = strtolower(trim($wanted));
            $direction = strtolower(trim($direction));
            if ($direction !== 'asc' && $direction !== 'desc') {
                continue;
            }

            foreach ($allowed as $column) {
                if (strtolower($column) !== $wanted || isset($seen[$column])) {
                    continue;
                }
                $seen[$column] = true;
                $resolved[] = ['column' => $column, 'descending' => $direction === 'desc'];
                break;
            }
        }

        return $resolved === [] ? $fallback : $resolved;
    }

    /**
     * O estado de ligação: `online`, `offline`, ou nada. Valor único, porque escolher os dois é
     * não filtrar.
     *
     * @param array<string, mixed> $params
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
