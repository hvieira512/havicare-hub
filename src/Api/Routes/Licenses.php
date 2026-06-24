<?php

namespace Hub\Api\Routes;

use Hub\Api\Support\CollectionResponse;
use Hub\Dashboard\DashboardDataAccess;

final class Licenses
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
        $softwareId = $this->queryFilter($params, 'softwareId', 'all');
        $items = $softwareId !== 'all'
            ? $this->db->licenses->findBySoftwareId((int)$softwareId)
            : $this->db->licenses->all();
        $available = [
            'softwareId' => $this->uniqueValues(array_map(
                static fn (array $s): string => (string)($s['id'] ?? ''),
                $this->db->software->all()
            )),
        ];

        return $this->collectionResponse($items, $page, $limit, ['softwareId' => $softwareId], $available);
    }

    public function create(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $softwareId = (int)($decoded['softwareId'] ?? 0);
        $licenseId = trim((string)($decoded['licenseId'] ?? ''));
        $name = trim((string)($decoded['name'] ?? ''));
        if ($softwareId <= 0) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'softwareId is required']];
        }
        if ($licenseId === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'licenseId is required']];
        }
        $id = $this->db->licenses->create($softwareId, $licenseId, $name);

        return ['status' => 'ok', 'id' => $id];
    }

    public function update(int $id, string $body): array
    {
        $existing = $this->db->licenses->findById($id);
        if ($existing === null) {
            return ['error' => ['code' => 'license_not_found', 'message' => 'License not found']];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $softwareId = (int)($decoded['softwareId'] ?? $existing['software_id']);
        $licenseId = trim((string)($decoded['licenseId'] ?? $existing['license_id']));
        $name = trim((string)($decoded['name'] ?? $existing['name']));
        $this->db->licenses->update($id, $softwareId, $licenseId, $name);

        return ['status' => 'ok'];
    }

    public function delete(int $id): array
    {
        $existing = $this->db->licenses->findById($id);
        if ($existing === null) {
            return ['error' => ['code' => 'license_not_found', 'message' => 'License not found']];
        }
        $this->db->licenses->delete($id);

        return ['status' => 'ok'];
    }
}
