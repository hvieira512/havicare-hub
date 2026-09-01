<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Tests\Support\MysqlDashboardTestCase;

/**
 * O `database/schema.sql` descreve a base de dados actual, sozinho. Corre **antes** das
 * migrações e em todas as invocações, o que faz dos dois ficheiros uma descrição só com duas
 * metades que têm de concordar. Divergindo, falham em silêncio nos dois sentidos:
 *
 * - o `schema.sql` a declarar o que uma migração larga recria-o na execução seguinte;
 * - o `schema.sql` a omitir o que uma migração acrescenta deixa uma base nova sem isso no dia
 *   em que a migração for apagada.
 *
 * Compara estrutura e não dados: o catálogo e o inventário têm passos próprios.
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
