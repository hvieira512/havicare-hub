<?php

namespace Hub\Api\Routes;

use Hub\Dashboard\ApiAuthContext;
use Hub\Dashboard\ApiTokenStore;
use Hub\Dashboard\DashboardDataAccess;
use Hub\Dashboard\DeviceMetadata;
use Hub\Log\Logger;

final class Auth
{
    public function __construct(
        private array $credentials,
        private ApiTokenStore $tokens,
        private DashboardDataAccess $db,
        private int $tokenTtlSeconds = 3600,
    ) {
    }

    public function login(string $body, string $requestId = ''): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::channel('api')->warning('API login rejected', [
                'request_id' => $requestId,
                'error_code' => 'invalid_request',
                'reason' => 'invalid_json',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        $username = trim((string)($decoded['username'] ?? ''));
        $password = (string)($decoded['password'] ?? '');
        if ($username === '' || $password === '') {
            Logger::channel('api')->warning('API login rejected', [
                'request_id' => $requestId,
                'username' => $username,
                'error_code' => 'invalid_request',
                'reason' => 'missing_credentials',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'username and password are required']];
        }

        $identity = $this->identityForCredentials($username, $password);
        if ($identity === null) {
            Logger::channel('api')->warning('API login rejected', [
                'request_id' => $requestId,
                'username' => $username,
                'error_code' => 'invalid_credentials',
            ]);
            return ['error' => ['code' => 'invalid_credentials', 'message' => 'Invalid credentials']];
        }

        Logger::channel('api')->info('API login accepted', [
            'request_id' => $requestId,
            'username' => (string)$identity['username'],
            'role' => (string)$identity['role'],
            'license_id' => $identity['licenseId'],
            'auth_source' => $identity['userId'] !== null ? 'db_user' : 'fallback_credential',
        ]);

        return [
            'status' => 'ok',
            'token' => $this->tokens->issue(
                (string)$identity['username'],
                (string)$identity['role'],
                $this->tokenTtlSeconds,
                $identity['userId'],
                $identity['licenseId']
            ),
        ];
    }

    private function identityForCredentials(string $username, string $password): ?array
    {
        $user = $this->db->apiUsers->findByUsername($username);
        if (is_array($user)) {
            $enabled = ((int)($user['enabled'] ?? 0)) === 1;
            $hash = (string)($user['password_hash'] ?? '');
            $role = trim((string)($user['role'] ?? ''));
            $licenseId = $role === ApiAuthContext::ROLE_LICENSE_CLIENT
                ? DeviceMetadata::normalizeLicenseId((string)($user['license_id'] ?? ''))
                : null;

            if ($enabled && $hash !== '' && password_verify($password, $hash) && in_array($role, ApiAuthContext::roles(), true)) {
                return [
                    'userId' => (int)($user['id'] ?? 0),
                    'username' => (string)($user['username'] ?? $username),
                    'role' => $role,
                    'licenseId' => $licenseId,
                ];
            }
        }

        return $this->fallbackIdentityForCredentials($username, $password);
    }

    private function fallbackIdentityForCredentials(string $username, string $password): ?array
    {
        foreach ($this->credentials as $credential) {
            if (!is_array($credential)) {
                continue;
            }

            $expectedUsername = trim((string)($credential['username'] ?? ''));
            $expectedPassword = (string)($credential['password'] ?? '');
            $role = trim((string)($credential['role'] ?? ''));
            if ($expectedUsername === '' || $expectedPassword === '' || $role === '') {
                continue;
            }

            if (hash_equals($expectedUsername, $username) && hash_equals($expectedPassword, $password)) {
                return [
                    'userId' => null,
                    'username' => $expectedUsername,
                    'role' => $role,
                    'licenseId' => isset($credential['licenseId']) && trim((string)$credential['licenseId']) !== ''
                        ? DeviceMetadata::normalizeLicenseId((string)$credential['licenseId'])
                        : null,
                ];
            }
        }

        return null;
    }
}
