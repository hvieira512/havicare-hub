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
     * O catálogo de referência vindo do código.
     *
     * **Só numa base vazia:** fornecedores, modelos e empresas são editáveis na dashboard, e
     * semeá-los a cada arranque fazia voltar o que alguém apagou. O inventário tem passo
     * próprio (`bin/seed-inventory.php`).
     *
     * O **catálogo de capacidades** é a excepção e reconcilia-se sempre: ninguém lhe escreve
     * fora daqui, e as duas cópias -- o código decide o canal do MQTT, a base decide o que a
     * dashboard mostra -- só se juntavam por uma migração à mão que, faltando, não dava erro.
     */
    private function syncReferenceCatalog(): void
    {
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
