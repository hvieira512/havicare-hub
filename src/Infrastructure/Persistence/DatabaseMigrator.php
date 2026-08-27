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
     * fornecedor×tipo, as capacidades e o template de cada modelo.
     *
     * Vem do `CapabilityCatalog` e do `SupplierCapabilityTemplate`, que são código, e é por
     * isso que uma instalação nova não precisa de replicar trinta e oito migrações para
     * chegar onde o código já está. Vinte e três delas não faziam outra coisa senão isso:
     * acrescentar uma capacidade, corrigir uma etiqueta, mover uma secção.
     *
     * **Só numa base vazia**, e é a parte que interessa. O catálogo é editável -- o
     * separador Catálogo cria e apaga modelos e fornecedores -- por isso semear a cada
     * arranque fazia voltar o que alguém tinha apagado de propósito. É a mesma classe de
     * defeito que o `schema.sql` tinha com a `diaper_sensor_settings`: um passo idempotente
     * a desfazer uma decisão deliberada, em silêncio, na execução seguinte.
     *
     * Numa base que já existe, quem faz evoluir o catálogo é uma migração -- que corre uma
     * vez, fica registada, e é onde uma decisão dessas se pode ler. Foi sempre o que estas
     * trinta e oito eram; o que o squash apagou foi a obrigação de as reviver do zero.
     *
     * Não confundir com o inventário. Esse são dispositivos reais e tem passo próprio
     * (`bin/seed-inventory.php`), porque uma migração que insere vinte e seis dispositivos
     * fá-los aparecer na base-modelo que os testes de integração clonam com dados, e um
     * teste que conta quatro encontra vinte e nove.
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
