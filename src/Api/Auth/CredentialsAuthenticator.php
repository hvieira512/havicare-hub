<?php

namespace Hub\Api\Auth;

use Hub\Api\Repository\ApiUserRepository;
use Hub\Domain\DeviceMetadata;
use Hub\Log\Logger;

final class CredentialsAuthenticator
{
    /**
     * @param list<array<string, mixed>> $credentials
     */
    public function __construct(
        private array $credentials,
        private ApiUserRepository $users,
    ) {
    }

    public function authenticate(string $username, string $password): ?array
    {
        $user = $this->users->findByUsername($username);
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
                    'licenseRefId' => isset($credential['licenseRefId']) ? (int)$credential['licenseRefId'] : null,
                    'companyId' => isset($credential['companyId']) ? (int)$credential['companyId'] : null,
                    'company' => isset($credential['company']) ? trim((string)$credential['company']) : null,
                ];
            }
        }

        return null;
    }
}
