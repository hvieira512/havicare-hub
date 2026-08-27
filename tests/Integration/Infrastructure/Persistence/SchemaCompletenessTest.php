<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Tests\Support\MysqlDashboardTestCase;

/**
 * O `database/schema.sql` descreve a base de dados actual, sozinho.
 *
 * O `DatabaseMigrator` executa o `schema.sql` **antes** das migrações, em todas as
 * invocações, e depois corre só as que ainda não estão registadas. Isso torna os dois
 * ficheiros uma única descrição com duas metades que têm de concordar, e quando divergem
 * as consequências são silenciosas nos dois sentidos:
 *
 * - O `schema.sql` a declarar algo que uma migração larga: a migração corre uma vez, e a
 *   execução seguinte recria o que ela apagou.
 * - O `schema.sql` a não declarar algo que uma migração acrescenta: uma base nova nasce sem
 *   a coluna e só a ganha porque a migração ainda existe. No dia em que essa migração for
 *   apagada, uma instalação nova fica sem ela.
 *
 * Por estrutura e não por dados: o catálogo de referência é semeado a partir do
 * `CapabilityCatalog` em código e o inventário tem um passo próprio, por isso nem um nem
 * outro pertence a esta comparação.
 */
final class SchemaCompletenessTest extends MysqlDashboardTestCase
{
    public function testSchemaFileAloneDescribesTheMigratedDatabase(): void
    {
        $migrated = $this->createDashboardDatabase()->pdo();

        $schemaOnlyName = $this->createEmptyDatabase();
        $schemaOnly = $this->pdoForDatabase($schemaOnlyName);
        $schema = file_get_contents(__DIR__ . '/../../../../database/schema.sql');
        self::assertIsString($schema);
        $schemaOnly->exec($schema);

        $expected = $this->tableNames($migrated);
        self::assertSame(
            $expected,
            $this->tableNames($schemaOnly),
            'O schema.sql e as migrações não produzem as mesmas tabelas. '
            . 'Uma tabela a mais no schema.sql é recriada a cada migrate; uma a menos '
            . 'nasce só porque a migração que a cria ainda não foi apagada.'
        );

        foreach ($expected as $table) {
            self::assertSame(
                $this->columnStructure($migrated, $table),
                $this->columnStructure($schemaOnly, $table),
                "As colunas de {$table} diferem entre o schema.sql e as migrações"
            );
            self::assertSame(
                $this->indexStructure($migrated, $table),
                $this->indexStructure($schemaOnly, $table),
                "Os índices de {$table} diferem entre o schema.sql e as migrações"
            );
            self::assertSame(
                $this->foreignKeyStructure($migrated, $table),
                $this->foreignKeyStructure($schemaOnly, $table),
                "As chaves estrangeiras de {$table} diferem entre o schema.sql e as migrações"
            );
        }
    }

    public function testTheDroppedDiaperTableStaysDropped(): void
    {
        // A `2026082802` largou-a e o `schema.sql` recriava-a na execução seguinte. Este
        // caso concreto fica preso à parte do teste geral porque foi o que o revelou.
        $pdo = $this->createDashboardDatabase()->pdo();

        self::assertNotContains('diaper_sensor_settings', $this->tableNames($pdo));
    }
}
