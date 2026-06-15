<?php

namespace Hub;

use Dotenv\Dotenv;

class Bootstrap
{
    public static function loadEnv(string $projectRoot): void
    {
        Dotenv::createUnsafeImmutable($projectRoot)->safeLoad();
    }
}
