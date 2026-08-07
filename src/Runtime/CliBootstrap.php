<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Bootstrap;
use Hub\Config;
use Hub\Configuration\HubConfigurationValidator;
use Hub\Infrastructure\Persistence\DashboardDatabase;
use Hub\Infrastructure\Persistence\DatabaseSchemaGuard;

/**
 * Shared preamble for the console entrypoints.
 */
final class CliBootstrap
{
    /**
     * @return array<string, mixed> the full hub config
     */
    public static function config(string $projectRoot, bool $validate = false): array
    {
        Bootstrap::loadEnv($projectRoot);
        $config = Config::load()->all();

        if ($validate) {
            (new HubConfigurationValidator())->validate($config);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config the full hub config
     * @param bool $assertSchema false for the migrator, which is what brings the
     *        schema up to date in the first place
     */
    public static function database(array $config, bool $assertSchema = true): DashboardDatabase
    {
        $database = new DashboardDatabase($config['database'] ?? []);

        if ($assertSchema) {
            (new DatabaseSchemaGuard($database->pdo()))->assertCurrent();
        }

        return $database;
    }
}
