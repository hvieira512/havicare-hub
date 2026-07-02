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
}
