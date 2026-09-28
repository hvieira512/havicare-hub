<?php

namespace Hub\Api\Http;

final class CollectionResponder
{
    public function respond(array $items, int $page, int $limit, array $appliedFilters, array $availableFilters): array
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
                // Um array vazio serializa como `[]`; o esquema declara estes dois como objecto.
                // A conversão garante `{}` mesmo nas listagens sem filtros (companies, suppliers).
                'applied' => $appliedFilters === [] ? (object)[] : $appliedFilters,
                'available' => $availableFilters === [] ? (object)[] : $availableFilters,
            ],
        ];
    }
}
