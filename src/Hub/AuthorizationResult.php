<?php

namespace Hub;

class AuthorizationResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly string $supplier = '',
        public readonly string $model = '',
    ) {
    }

    public static function allow(string $supplier = '', string $model = ''): self
    {
        return new self(true, null, $supplier, $model);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
