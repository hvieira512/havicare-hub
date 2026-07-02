<?php

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Domain\DeviceMetadata;

class ApiUserService
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
            'role' => $this->query->filter($params, 'role'),
            'enabled' => $this->query->filter($params, 'enabled'),
        ];
        $users = array_values(array_filter($this->db->apiUsers->all(), static function (array $user) use ($filters): bool {
            $enabled = ((int)($user['enabled'] ?? 0)) === 1 ? 'true' : 'false';
            $role = (string)($user['role'] ?? '');

            return (($filters['role'] ?? null) === null || $role === $filters['role'])
                && (($filters['enabled'] ?? null) === null || $enabled === $filters['enabled']);
        }));

        return $this->collection->respond($users, $page, $limit, $filters, [
            'role' => ApiAuthContext::roles(),
            'enabled' => ['true', 'false'],
        ]);
    }

    public function create(string $body): array
    {
        $payload = $this->payload($body, true);
        if (isset($payload['error'])) {
            return $payload;
        }

        $username = (string)$payload['username'];
        if ($this->db->apiUsers->findByUsername($username) !== null) {
            return ['error' => ['code' => 'user_exists', 'message' => 'Username already exists']];
        }

        $id = $this->db->apiUsers->create(
            $username,
            password_hash((string)$payload['password'], PASSWORD_DEFAULT),
            (string)$payload['role'],
            (int)$payload['licenseId'],
            (bool)$payload['enabled']
        );

        return ['status' => 'ok', 'id' => $id];
    }

    public function update(int $id, string $body): array
    {
        if ($this->db->apiUsers->findById($id) === null) {
            return ['error' => ['code' => 'user_not_found', 'message' => 'API user not found']];
        }

        $payload = $this->payload($body, false);
        if (isset($payload['error'])) {
            return $payload;
        }

        $username = (string)$payload['username'];
        if ($this->db->apiUsers->usernameExistsForDifferentId($id, $username)) {
            return ['error' => ['code' => 'user_exists', 'message' => 'Username already exists']];
        }

        $passwordHash = (string)($payload['password'] ?? '') !== ''
            ? password_hash((string)$payload['password'], PASSWORD_DEFAULT)
            : null;
        $this->db->apiUsers->update(
            $id,
            $username,
            (string)$payload['role'],
            (int)$payload['licenseId'],
            (bool)$payload['enabled'],
            $passwordHash
        );

        return ['status' => 'ok', 'id' => $id];
    }

    public function delete(int $id): array
    {
        if ($this->db->apiUsers->findById($id) === null) {
            return ['error' => ['code' => 'user_not_found', 'message' => 'API user not found']];
        }

        $this->db->apiUsers->delete($id);

        return ['status' => 'ok'];
    }

    private function payload(string $body, bool $passwordRequired): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        $username = trim((string)($decoded['username'] ?? ''));
        $password = (string)($decoded['password'] ?? '');
        $role = trim((string)($decoded['role'] ?? ''));
        $licenseId = DeviceMetadata::normalizeLicenseId((string)($decoded['licenseId'] ?? $decoded['license_id'] ?? ''));
        $enabled = array_key_exists('enabled', $decoded) ? (bool)$decoded['enabled'] : true;

        if ($username === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'username is required']];
        }
        if ($passwordRequired && trim($password) === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'password is required']];
        }
        if ($role === '') {
            $role = ApiAuthContext::ROLE_LICENSE_CLIENT;
        }
        if (!in_array($role, ApiAuthContext::roles(), true)) {
            return ['error' => ['code' => 'invalid_role', 'message' => 'role must be hub_admin or license_client']];
        }
        if ($role === ApiAuthContext::ROLE_LICENSE_CLIENT && $licenseId === 0) {
            return ['error' => ['code' => 'invalid_license', 'message' => 'licenseId is required for license clients']];
        }
        if ($role === ApiAuthContext::ROLE_HUB_ADMIN) {
            $licenseId = 0;
        }

        return [
            'username' => $username,
            'password' => trim($password),
            'role' => $role,
            'licenseId' => $licenseId,
            'enabled' => $enabled,
        ];
    }
}
