<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\MigrationRunner;
use Hub\Log\Logger;
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
     * O catálogo de referência vindo do código, só numa base vazia, para não repor o que a
     * dashboard apagou. O catálogo de capacidades é a excepção e reconcilia-se sempre.
     */
    private function syncReferenceCatalog(): void
    {
        // A linha do modelo guarda o nome de um ficheiro em `var/`, que está no gitignore: sem
        // o copiar, a dashboard mostra uma imagem partida e não há erro em lado nenhum.
        (new InventorySeeder())->copyMissingModelImages();

        $seeder = new ReferenceCatalogSeeder();

        if ((int)$this->pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            $seeder->seedReferenceData($this->pdo);
            $seeder->seedMissingModelCapabilities($this->pdo);
            return;
        }

        $reconciled = $seeder->reconcileCapabilities($this->pdo);
        $seeder->seedMissingModelCapabilities($this->pdo);

        // Calado quando não há nada a dizer, e explícito quando há: uma alteração de catálogo
        // no arranque não pode ser uma coisa que aconteça sem deixar rasto.
        if (array_filter($reconciled) !== []) {
            Logger::channel('hub')->info('Capability catalogue reconciled from code', $reconciled);
        }
    }
}
