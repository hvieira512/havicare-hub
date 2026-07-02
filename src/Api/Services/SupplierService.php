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
        $filters = [
            'enabled' => $this->query->filter($params, 'enabled'),
        ];
        $suppliers = array_values(array_filter($this->db->suppliers->all(), static function (array $supplier) use ($filters): bool {
            $enabled = ((int)($supplier['enabled'] ?? 0)) === 1 ? 'true' : 'false';

            return (($filters['enabled'] ?? null) === null || $enabled === $filters['enabled']);
        }));
        $available = [
            'enabled' => ['true', 'false'],
        ];

        return $this->collection->respond($suppliers, $page, $limit, $filters, $available);
    }

    public function create(string $body): array
    {
        return ['error' => ['code' => 'read_only', 'message' => 'Suppliers are defined in code and cannot be created through the API']];
    }

    public function update(int $id, string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $enabled = array_key_exists('enabled', $decoded) ? (bool)$decoded['enabled'] : null;
        if ($this->db->suppliers->findById($id) === null) {
            return ['error' => ['code' => 'supplier_not_found', 'message' => 'Supplier not found']];
        }
        if ($enabled !== null) {
            $this->db->suppliers->setEnabled($id, $enabled);
        } else {
            return ['error' => ['code' => 'read_only', 'message' => 'Only toggling enabled is allowed; supplier properties are defined in code']];
        }

        return ['status' => 'ok'];
    }

    public function delete(int $id): array
    {
        return ['error' => ['code' => 'read_only', 'message' => 'Suppliers are defined in code and cannot be deleted through the API']];
    }
}
