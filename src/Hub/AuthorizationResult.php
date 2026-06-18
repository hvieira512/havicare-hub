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
        public readonly string $deviceType = 'watch',
        public readonly string $licenseId = '0',
    ) {
    }

    public static function allow(
        string $imei = '',
        string $supplier = '',
        string $model = '',
        string $deviceType = 'watch',
        string $licenseId = '0'
    ): self
    {
        return new self(true, null, $imei, $supplier, $model, $deviceType, $licenseId);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
