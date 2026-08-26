<?php

namespace Hub\Api\Services;

use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Repository\ApiDataAccess;

class SupplierService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

    private CollectionQuery $query;
    private CollectionResponder $collection;

    public function __construct(
        private ApiDataAccess $db,
        ?CollectionQuery $query = null,
        ?CollectionResponder $collection = null,
    ) {
        $this->query = $query ?? new CollectionQuery();
        $this->collection = $collection ?? new CollectionResponder();
    }

    public function list(string $query = ''): array
    {
        $params = $this->query->params($query);
        $page = $this->query->page($params);
        $limit = $this->query->limit($params, self::DEFAULT_COLLECTION_LIMIT);

        // Sem filtros: um fornecedor tem nome e contagem de modelos, e mais nada por onde
        // filtrar.
        return $this->collection->respond($this->db->suppliers->all(), $page, $limit, [], []);
    }
}
