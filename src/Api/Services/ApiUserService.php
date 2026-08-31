<?php

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\ApiError;
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

    public function create(array $payload): array
    {
        $fields = $this->fields($payload, true);
        if (isset($fields['error'])) {
            return $fields;
        }

        $username = (string)$fields['username'];
        if ($this->db->apiUsers->findByUsername($username) !== null) {
            return ApiError::userExists()->toArray();
        }

        $id = $this->db->apiUsers->create(
            $username,
            password_hash((string)$fields['password'], PASSWORD_DEFAULT),
            (string)$fields['role'],
            (int)$fields['licenseId'],
            (bool)$fields['enabled'],
            $fields['licenseRefId'],
        );

        return ['status' => 'ok', 'id' => $id];
    }

    public function update(int $id, array $payload): array
    {
        if ($this->db->apiUsers->findById($id) === null) {
            return ApiError::userNotFound()->toArray();
        }

        $fields = $this->fields($payload, false);
        if (isset($fields['error'])) {
            return $fields;
        }

        $username = (string)$fields['username'];
        if ($this->db->apiUsers->usernameExistsForDifferentId($id, $username)) {
            return ApiError::userExists()->toArray();
        }

        $passwordHash = (string)($fields['password'] ?? '') !== ''
            ? password_hash((string)$fields['password'], PASSWORD_DEFAULT)
            : null;
        $this->db->apiUsers->update(
            $id,
            $username,
            (string)$fields['role'],
            (int)$fields['licenseId'],
            (bool)$fields['enabled'],
            $passwordHash,
            $fields['licenseRefId'],
        );

        return ['status' => 'ok', 'id' => $id];
    }

    public function delete(int $id): array
    {
        if ($this->db->apiUsers->findById($id) === null) {
            return ApiError::userNotFound()->toArray();
        }

        $this->db->apiUsers->delete($id);

        return ['status' => 'ok'];
    }

    private function fields(array $payload, bool $passwordRequired): array
    {
        $username = trim((string)($payload['username'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        $role = trim((string)($payload['role'] ?? ''));
        $licenseId = DeviceMetadata::normalizeLicenseId((string)($payload['licenseId'] ?? $payload['license_id'] ?? ''));
        $licenseRefId = (int)($payload['licenseRefId'] ?? $payload['license_ref_id'] ?? 0);
        $companyId = (int)($payload['companyId'] ?? $payload['company_id'] ?? 0);
        $enabled = array_key_exists('enabled', $payload) ? (bool)$payload['enabled'] : true;

        if ($username === '') {
            return ApiError::invalidRequest('username is required')->toArray();
        }
        if ($passwordRequired && trim($password) === '') {
            return ApiError::invalidRequest('password is required')->toArray();
        }
        if ($role === '') {
            $role = ApiAuthContext::ROLE_LICENSE_CLIENT;
        }
        if (!in_array($role, ApiAuthContext::roles(), true)) {
            return ApiError::invalidRole()->toArray();
        }
        if ($role === ApiAuthContext::ROLE_LICENSE_CLIENT) {
            $license = $licenseRefId > 0
                ? $this->db->licenses->findById($licenseRefId)
                : ($companyId > 0 && $licenseId > 0
                    ? $this->db->licenses->findByCompanyAndLicense($companyId, $licenseId)
                    : null);
            if ($license === null) {
                return ApiError::invalidLicense()->toArray();
            }
            $licenseRefId = (int)$license['id'];
            $licenseId = DeviceMetadata::normalizeLicenseId((string)$license['license_id']);
        }
        if ($role === ApiAuthContext::ROLE_HUB_ADMIN) {
            $licenseId = 0;
            $licenseRefId = null;
        }

        return [
            'username' => $username,
            'password' => trim($password),
            'role' => $role,
            'licenseId' => $licenseId,
            'licenseRefId' => $licenseRefId,
            'enabled' => $enabled,
        ];
    }
}
