<?php

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Repository\ApiDataAccess;

class LicenseService
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
        $companyId = $this->query->filter($params, 'companyId');
        $items = $companyId !== null
            ? $this->db->licenses->findByCompanyId((int)$companyId)
            : $this->db->licenses->all();
        $available = [
            'companyId' => $this->collection->uniqueValues(array_map(
                static fn (array $s): string => (string)($s['id'] ?? ''),
                $this->db->companies->all()
            )),
        ];

        return $this->collection->respond($items, $page, $limit, ['companyId' => $companyId], $available);
    }

    public function create(array $payload): array
    {
        $companyId = (int)($payload['companyId'] ?? 0);
        $licenseId = (int)($payload['licenseId'] ?? 0);
        $name = trim((string)($payload['name'] ?? ''));
        if ($companyId <= 0) {
            return ApiError::invalidRequest('companyId is required')->toArray();
        }
        if ($licenseId <= 0) {
            return ApiError::invalidRequest('licenseId is required')->toArray();
        }
        $id = $this->db->licenses->create($companyId, $licenseId, $name);

        return ['status' => 'ok', 'id' => $id];
    }

    public function update(int $id, array $payload): array
    {
        $existing = $this->db->licenses->findById($id);
        if ($existing === null) {
            return ApiError::licenseNotFound()->toArray();
        }
        $companyId = (int)($payload['companyId'] ?? $existing['company_id']);
        $licenseId = (int)($payload['licenseId'] ?? $existing['license_id']);
        $name = trim((string)($payload['name'] ?? $existing['name']));
        $this->db->licenses->update($id, $companyId, $licenseId, $name);

        return ['status' => 'ok'];
    }

    public function delete(int $id): array
    {
        $existing = $this->db->licenses->findById($id);
        if ($existing === null) {
            return ApiError::licenseNotFound()->toArray();
        }
        $this->db->licenses->delete($id);

        return ['status' => 'ok'];
    }
}
