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
     * O catálogo de referência de uma base de dados nova: fornecedores, modelos, os pares
     * fornecedor×tipo, as capacidades e o template de cada modelo. Vem do `CapabilityCatalog`
     * e do `SupplierCapabilityTemplate`, que são código.
     *
     * **Só numa base vazia.** O catálogo é editável -- o separador Catálogo cria e apaga
     * modelos e fornecedores --, e semear a cada arranque fazia voltar o que alguém tinha
     * apagado de propósito. Numa base que já existe, quem faz evoluir o catálogo é uma
     * migração, que corre uma vez e fica registada.
     *
     * Não confundir com o inventário: esses são dispositivos reais e têm passo próprio
     * (`bin/seed-inventory.php`), porque uma migração que os insere fá-los aparecer na
     * base-modelo que os testes de integração clonam com dados.
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
