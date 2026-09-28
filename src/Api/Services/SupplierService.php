<?php

namespace Hub\Api\Services;

use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\SupplierColumns;
use Hub\Api\Repository\ApiDataAccess;

class SupplierService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

    private CollectionPresenter $presenter;

    public function __construct(private ApiDataAccess $db)
    {
        $this->presenter = new CollectionPresenter();
    }

    public function list(string $query = ''): array
    {
        return $this->presenter->present(
            $this->db->suppliers->all(),
            SupplierColumns::definition(),
            $this->presenter->params($query),
            self::DEFAULT_COLLECTION_LIMIT,
        );
    }
}
