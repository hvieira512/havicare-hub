<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class MigrationRunner
{
    /**
     * O `GET_LOCK` do MySQL tem âmbito de servidor e não de base de dados, e por isso este
     * nome serializa as migrações de todas as instâncias que partilhem o servidor -- o que é
     * mais do que se precisa, mas é o lado seguro.
     *
     * Ao renomeá-lo, as duas instâncias têm de ficar na mesma versão antes de alguém migrar as
     * duas ao mesmo tempo: enquanto uma tiver o nome antigo e a outra o novo, os locks não se
     * veem um ao outro.
     */
    private const LOCK_NAME = 'havicare_hub_migrations';

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
