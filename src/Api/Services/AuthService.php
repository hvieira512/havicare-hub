<?php

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Http\ApiError;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Domain\DeviceMetadata;
use Hub\Log\Logger;

class AuthService
{
    public function __construct(
        private ApiTokenStore $tokens,
        private ApiDataAccess $db,
        private int $tokenTtlSeconds = 3600,
        private int $refreshTokenTtlSeconds = 2592000,
    ) {
    }

    public function login(array $payload, string $requestId = ''): array
    {
        $refreshToken = trim((string)($payload['refresh_token'] ?? ''));
        if ($refreshToken !== '') {
            return $this->refresh($refreshToken, $requestId);
        }

        $username = trim((string)($payload['username'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        if ($username === '' || $password === '') {
            Logger::channel('api')->warning('API login rejected', [
                'request_id' => $requestId,
                'username' => $username,
                'error_code' => 'invalid_request',
                'reason' => 'missing_credentials',
            ]);
            return ApiError::invalidRequest('username and password are required')->toArray();
        }

        $identity = $this->identityForCredentials($username, $password);
        if ($identity === null) {
            Logger::channel('api')->warning('API login rejected', [
                'request_id' => $requestId,
                'username' => $username,
                'error_code' => 'invalid_credentials',
            ]);
            return ApiError::invalidCredentials()->toArray();
        }

        Logger::channel('api')->info('API login accepted', [
            'request_id' => $requestId,
            'username' => (string)$identity['username'],
            'role' => (string)$identity['role'],
            'license_id' => $identity['licenseId'],
            'auth_source' => 'db_user',
        ]);

        return [
            'status' => 'ok',
            'token' => $this->tokens->issueTokenPair(
                (string)$identity['username'],
                (string)$identity['role'],
                $this->tokenTtlSeconds,
                $this->refreshTokenTtlSeconds,
                $identity['userId'],
                $identity['licenseId'],
                $identity['licenseRefId'],
                $identity['companyId'],
                $identity['company'],
            ),
        ];
    }

    private function refresh(string $refreshToken, string $requestId = ''): array
    {
        $token = $this->tokens->refreshAccessToken($refreshToken, $this->tokenTtlSeconds, $this->refreshTokenTtlSeconds);
        if ($token === null) {
            Logger::channel('api')->warning('API token refresh rejected', [
                'request_id' => $requestId,
                'error_code' => 'invalid_refresh_token',
            ]);

            return ApiError::invalidRefreshToken()->toArray();
        }

        Logger::channel('api')->info('API token refreshed', [
            'request_id' => $requestId,
            'role' => (string)($token['role'] ?? ''),
            'license_id' => $token['license_id'] ?? null,
        ]);

        return [
            'status' => 'ok',
            'token' => $token,
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
            $licenseRefId = $role === ApiAuthContext::ROLE_LICENSE_CLIENT ? (int)($user['license_ref_id'] ?? 0) : null;
            $companyId = $role === ApiAuthContext::ROLE_LICENSE_CLIENT ? (int)($user['company_id'] ?? 0) : null;
            $company = $role === ApiAuthContext::ROLE_LICENSE_CLIENT ? trim((string)($user['company_name'] ?? '')) : null;

            $tenantIsValid = $role !== ApiAuthContext::ROLE_LICENSE_CLIENT
                || ($licenseId > 0 && $licenseRefId > 0 && $companyId > 0 && $company !== '');
            if ($enabled && $tenantIsValid && $hash !== '' && password_verify($password, $hash) && in_array($role, ApiAuthContext::roles(), true)) {
                return [
                    'userId' => (int)($user['id'] ?? 0),
                    'username' => (string)($user['username'] ?? $username),
                    'role' => $role,
                    'licenseId' => $licenseId,
                    'licenseRefId' => $licenseRefId,
                    'companyId' => $companyId,
                    'company' => $company,
                ];
            }
        }

        return null;
    }
}
