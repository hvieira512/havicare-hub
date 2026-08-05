<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use PDO;

final class DatabaseSchemaGuard
{
    public function __construct(
        private PDO $pdo,
        private ?DatabaseMigrationPlan $plan = null,
    ) {
        $this->plan ??= new DatabaseMigrationPlan();
    }

    public function assertCurrent(): void
    {
        $table = $this->pdo->query("SHOW TABLES LIKE 'schema_migrations'");
        if ($table === false || $table->fetchColumn() === false) {
            throw new \RuntimeException('Database schema is not initialized; run php bin/migrate.php');
        }

        $applied = array_flip(array_map('strval', $this->pdo
            ->query('SELECT version FROM schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN)));
        $missing = array_values(array_filter(
            $this->plan->versions(),
            static fn(string $version): bool => !isset($applied[$version])
        ));
        if ($missing !== []) {
            throw new \RuntimeException('Database migrations are pending: ' . implode(', ', $missing));
        }
    }
}
