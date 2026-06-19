<?php

namespace Hub\Api\Routes;

use Hub\Api\Support\CollectionResponse;
use Hub\Dashboard\DashboardDataAccess;

final class Suppliers
{
    use CollectionResponse;

    private const DEFAULT_COLLECTION_LIMIT = 20;

    public function __construct(private DashboardDataAccess $db)
    {
    }

    public function list(string $query = ''): array
    {
        $params = $this->queryParams($query);
        $page = $this->queryPage($params);
        $limit = $this->queryLimit($params, self::DEFAULT_COLLECTION_LIMIT);
        $filters = [
            'enabled' => $this->queryFilter($params, 'enabled', 'all'),
        ];
        $suppliers = array_values(array_filter($this->db->suppliers->all(), static function (array $supplier) use ($filters): bool {
            $enabled = ((int)($supplier['enabled'] ?? 0)) === 1 ? 'true' : 'false';

            return (($filters['enabled'] ?? 'all') === 'all' || $enabled === $filters['enabled']);
        }));

        return $this->collectionResponse($suppliers, $page, $limit, $filters, [
            'enabled' => ['true', 'false'],
        ]);
    }

    public function create(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $name = trim((string)($decoded['name'] ?? ''));
        if ($name === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'name is required']];
        }
        $id = $this->db->suppliers->create($name);

        return ['status' => 'ok', 'id' => $id];
    }

    public function update(int $id, string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $newName = isset($decoded['name']) ? trim((string)$decoded['name']) : null;
        $enabled = array_key_exists('enabled', $decoded) ? (bool)$decoded['enabled'] : null;
        if ($newName !== null && $newName === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'name cannot be empty']];
        }
        if ($this->db->suppliers->findById($id) === null) {
            return ['error' => ['code' => 'supplier_not_found', 'message' => 'Supplier not found']];
        }
        if ($newName !== null) {
            $this->db->suppliers->rename($id, $newName);
        }
        if ($enabled !== null) {
            $this->db->suppliers->setEnabled($id, $enabled);
        }

        return ['status' => 'ok'];
    }

    public function delete(int $id): array
    {
        $supplier = $this->db->suppliers->findById($id);
        if ($supplier === null) {
            return ['error' => ['code' => 'supplier_not_found', 'message' => 'Supplier not found']];
        }
        $count = $this->db->suppliers->countModels($id);
        if ($count > 0) {
            return ['error' => ['code' => 'supplier_in_use', 'message' => "Cannot delete supplier '{$supplier['name']}': {$count} model(s) reference it"]];
        }
        $this->db->suppliers->delete($id);

        return ['status' => 'ok'];
    }
}
