<?php

namespace Hub\Api\Auth;

final class ApiAuthContext
{
    public const ROLE_HUB_ADMIN = 'hub_admin';
    public const ROLE_LICENSE_CLIENT = 'license_client';
    public const ROLE_TENANT_CLIENT = self::ROLE_LICENSE_CLIENT;

    public function __construct(
        public readonly ?int $userId,
        public readonly string $username,
        public readonly string $role,
        public readonly ?int $licenseId = null,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_HUB_ADMIN;
    }

    public function isLicenseClient(): bool
    {
        return $this->role === self::ROLE_LICENSE_CLIENT;
    }

    public function canAccessLicense(int $licenseId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->isLicenseClient()
            && $this->licenseId !== null
            && $this->licenseId > 0
            && $licenseId > 0
            && $this->licenseId === $licenseId;
    }

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [self::ROLE_HUB_ADMIN, self::ROLE_LICENSE_CLIENT];
    }
}
