<?php

namespace App\Hub;

class AuthorizationResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly string $model = '',
    ) {
    }

    public static function allow(string $model = ''): self
    {
        return new self(true, null, $model);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
