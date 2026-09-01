<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\MigrationRunner;
use PDO;

final class DatabaseMigrator
{
    public function __construct(
        private PDO $pdo,
        private ?DatabaseMigrationPlan $plan = null,
    ) {
        $this->plan ??= new DatabaseMigrationPlan();
    }

    public function migrate(): void
    {
        $schemaPath = __DIR__ . '/../../../database/schema.sql';
        $schema = file_get_contents($schemaPath);
        if (!is_string($schema) || trim($schema) === '') {
            throw new \RuntimeException('database schema file is missing or empty');
        }

        $this->pdo->exec($schema);
        (new MigrationRunner($this->pdo, $this->plan->migrations()))->run();
        $this->syncReferenceCatalog();
    }

    /**
     * O catálogo de referência de uma base nova -- fornecedores, modelos, capacidades e
     * templates --, vindo do código.
     *
     * **Só numa base vazia:** o catálogo é editável, e semear a cada arranque fazia voltar o
     * que alguém apagou. Numa base existente, quem o faz evoluir é uma migração.
     *
     * O inventário tem passo próprio (`bin/seed-inventory.php`), senão aparecia na base-modelo
     * que os testes de integração clonam.
     */
    private function syncReferenceCatalog(): void
    {
        $catalogued = (int)$this->pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn();
        if ($catalogued > 0) {
            return;
        }

        $seeder = new ReferenceCatalogSeeder();
        $seeder->seedReferenceData($this->pdo);
        $seeder->seedMissingModelCapabilities($this->pdo);
    }
}
