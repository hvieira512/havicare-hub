<?php

namespace Hub\Api\Support;

trait CollectionResponse
{
    private function queryParams(string $query): array
    {
        if ($query === '') {
            return [];
        }

        parse_str($query, $params);

        return is_array($params) ? $params : [];
    }

    private function queryPage(array $params): int
    {
        return max(1, (int)($params['page'] ?? 1));
    }

    private function queryLimit(array $params, int $default): int
    {
        return max(1, (int)($params['limit'] ?? $default));
    }

    private function queryFilter(array $params, string $key, ?string $default = null): ?string
    {
        $value = trim((string)($params[$key] ?? ''));

        return $value === '' ? $default : $value;
    }

    private function collectionResponse(array $items, int $page, int $limit, array $appliedFilters, array $availableFilters): array
    {
        $total = count($items);
        $totalPages = max(1, (int)ceil($total / max(1, $limit)));
        $currentPage = min(max(1, $page), $totalPages);
        $offset = ($currentPage - 1) * $limit;

        return [
            'data' => array_values(array_slice($items, $offset, $limit)),
            'pagination' => [
                'limit' => $limit,
                'page' => $currentPage,
                'total_pages' => $totalPages,
                'total' => $total,
            ],
            'filters' => [
                'applied' => $appliedFilters,
                'available' => $availableFilters,
            ],
        ];
    }

    private function uniqueValues(array $values): array
    {
        $filtered = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string)$value),
            $values
        ), static fn (string $value): bool => $value !== ''));
        $unique = array_values(array_unique($filtered));
        usort($unique, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

        return $unique;
    }
}
