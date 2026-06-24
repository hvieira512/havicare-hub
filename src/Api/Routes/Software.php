<?php

namespace Hub\Api\Routes;

use Hub\Api\Support\CollectionResponse;
use Hub\Dashboard\DashboardDataAccess;

final class Software
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
        $items = $this->db->software->all();

        return $this->collectionResponse($items, $page, $limit, [], []);
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
        $id = $this->db->software->create($name);
        if ($id <= 0) {
            return ['error' => ['code' => 'duplicate', 'message' => 'Software with this name already exists']];
        }

        return ['status' => 'ok', 'id' => $id];
    }

    public function update(int $id, string $body): array
    {
        $existing = $this->db->software->findById($id);
        if ($existing === null) {
            return ['error' => ['code' => 'software_not_found', 'message' => 'Software not found']];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $name = trim((string)($decoded['name'] ?? ''));
        if ($name === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'name is required']];
        }
        $this->db->software->update($id, $name);

        return ['status' => 'ok'];
    }

    public function delete(int $id): array
    {
        $existing = $this->db->software->findById($id);
        if ($existing === null) {
            return ['error' => ['code' => 'software_not_found', 'message' => 'Software not found']];
        }
        $this->db->software->delete($id);

        return ['status' => 'ok'];
    }
}
