<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class MigrationRunner
{
    private const LOCK_NAME = 'hitecosystem_devices_hub_migrations';

    /**
     * @param list<Migration> $migrations
     */
    public function __construct(
        private PDO $pdo,
        private array $migrations,
    ) {
    }

    public function run(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        if (!$this->acquireLock()) {
            throw new \RuntimeException('Could not acquire the database migration lock');
        }

        try {
            $applied = array_flip($this->appliedVersions());
            foreach ($this->migrations as $migration) {
                $version = trim($migration->version());
                if ($version === '') {
                    throw new \LogicException('Migration versions cannot be empty');
                }
                if (isset($applied[$version])) {
                    continue;
                }

                $migration->up($this->pdo);
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
                $stmt->execute([$version]);
                $applied[$version] = true;
            }
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @return list<string>
     */
    private function appliedVersions(): array
    {
        $stmt = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version');
        return array_map('strval', $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []);
    }

    private function acquireLock(): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 30)');
        $stmt->execute([self::LOCK_NAME]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([self::LOCK_NAME]);
    }
}
