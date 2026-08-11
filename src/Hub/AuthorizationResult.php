<?php

namespace Hub;

class AuthorizationResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly string $imei = '',
        public readonly string $supplier = '',
        public readonly string $model = '',
        public readonly string $commercialName = '',
        public readonly string $deviceType = 'watch',
        public readonly int $licenseId = 0,
        public readonly string $company = 'null',
    ) {
    }

    public static function allow(
        string $imei = '',
        string $supplier = '',
        string $model = '',
        string $commercialName = '',
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $company = 'null',
    ): self
    {
        return new self(true, null, $imei, $supplier, $model, $commercialName, $deviceType, $licenseId, $company);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
