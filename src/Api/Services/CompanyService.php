<?php

namespace Hub\Api\Services;

use Hub\Domain\DeviceMetadata;
use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Repository\ApiDataAccess;

class CompanyService
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
        $items = $this->db->companies->all();

        return $this->collection->respond($items, $page, $limit, [], []);
    }

    public function create(array $payload): array
    {
        $name = DeviceMetadata::normalizeCompany((string)($payload['name'] ?? ''));
        if ($name === '') {
            return ApiError::invalidRequest('name is required')->toArray();
        }
        $id = $this->db->companies->create($name);
        if ($id <= 0) {
            return ApiError::duplicateCompany()->toArray();
        }

        return ['status' => 'ok', 'id' => $id];
    }

    public function update(int $id, array $payload): array
    {
        $existing = $this->db->companies->findById($id);
        if ($existing === null) {
            return ApiError::companyNotFound()->toArray();
        }
        $name = DeviceMetadata::normalizeCompany((string)($payload['name'] ?? ''));
        if ($name === '') {
            return ApiError::invalidRequest('name is required')->toArray();
        }
        $this->db->companies->update($id, $name);

        return ['status' => 'ok'];
    }

    public function delete(int $id): array
    {
        $existing = $this->db->companies->findById($id);
        if ($existing === null) {
            return ApiError::companyNotFound()->toArray();
        }
        $this->db->companies->delete($id);

        return ['status' => 'ok'];
    }
}
