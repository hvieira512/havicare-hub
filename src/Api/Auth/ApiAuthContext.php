<?php

namespace Hub\Api\Auth;

final class ApiAuthContext
{
    public const ROLE_HUB_ADMIN = 'hub_admin';
    public const ROLE_LICENSE_CLIENT = 'license_client';

    public function __construct(
        public readonly ?int $userId,
        public readonly string $username,
        public readonly string $role,
        public readonly ?int $licenseId = null,
        public readonly ?int $licenseRefId = null,
        public readonly ?int $companyId = null,
        public readonly ?string $company = null,
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

    public function canAccessTenant(string $company, int $licenseId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->isLicenseClient()
            && $this->licenseRefId !== null
            && $this->licenseRefId > 0
            && $this->companyId !== null
            && $this->companyId > 0
            && $this->company !== null
            && strcasecmp(trim($this->company), trim($company)) === 0
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
