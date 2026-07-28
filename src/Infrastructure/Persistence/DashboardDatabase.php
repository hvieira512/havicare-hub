<?php

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\MigrationRunner;
use Hub\Infrastructure\Persistence\Migration\Version2026072401UpgradeLegacySchema;
use Hub\Infrastructure\Persistence\Migration\Version2026072402SeedReferenceCatalog;
use Hub\Infrastructure\Persistence\Migration\Version2026072403RebuildModelCapabilities;
use Hub\Infrastructure\Persistence\Migration\Version2026072404SeedModelCapabilities;
use Hub\Infrastructure\Persistence\Migration\Version2026072405NormalizeConfigurationKeys;
use Hub\Infrastructure\Persistence\Migration\Version2026072406AddDashboardNotifications;
use Hub\Infrastructure\Persistence\Migration\Version2026072801SyncWonlexAdultHealthCapabilities;
use PDO;

final class DashboardDatabase
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $driver = strtolower(trim((string)($config['driver'] ?? '')));
        if ($driver !== 'mysql') {
            throw new \InvalidArgumentException('DashboardDatabase only supports the mysql driver');
        }

        $charset = trim((string)($config['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($config['host'] ?? '127.0.0.1'),
            (int)($config['port'] ?? 3306),
            (string)($config['name'] ?? 'hitecosystem_hub'),
            $charset,
        );
        $this->pdo = new PDO($dsn, (string)($config['username'] ?? ''), (string)($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->bootstrapSchema();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function bootstrapSchema(): void
    {
        $schemaPath = __DIR__ . '/../../../database/schema.sql';
        $schema = file_get_contents($schemaPath);
        if (!is_string($schema) || trim($schema) === '') {
            throw new \RuntimeException('database schema file is missing or empty');
        }

        $this->pdo->exec($schema);
        (new MigrationRunner($this->pdo, [
            new Version2026072401UpgradeLegacySchema(),
            new Version2026072402SeedReferenceCatalog(),
            new Version2026072403RebuildModelCapabilities(),
            new Version2026072404SeedModelCapabilities(),
            new Version2026072405NormalizeConfigurationKeys(),
            new Version2026072406AddDashboardNotifications(),
            new Version2026072801SyncWonlexAdultHealthCapabilities(),
        ]))->run();
    }
}
