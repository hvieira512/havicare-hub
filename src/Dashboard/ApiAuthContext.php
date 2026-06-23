<?php

namespace Hub\Dashboard;

final class ApiAuthContext
{
    public const ROLE_HUB_ADMIN = 'hub_admin';
    public const ROLE_LICENSE_CLIENT = 'license_client';
    public const ROLE_TENANT_CLIENT = self::ROLE_LICENSE_CLIENT;

    public function __construct(
        public readonly ?int $userId,
        public readonly string $username,
        public readonly string $role,
        public readonly ?string $licenseId = null,
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

    public function canAccessLicense(string $licenseId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $licenseId = trim($licenseId);
        return $this->isLicenseClient()
            && $this->licenseId !== null
            && $this->licenseId !== ''
            && $licenseId !== ''
            && $licenseId !== '0'
            && hash_equals($this->licenseId, $licenseId);
    }

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [self::ROLE_HUB_ADMIN, self::ROLE_LICENSE_CLIENT];
    }
}
