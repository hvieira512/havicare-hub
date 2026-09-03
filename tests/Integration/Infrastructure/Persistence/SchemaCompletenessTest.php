<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Domain\DeviceMetadata;
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

    /**
     * O `device_type` é um `ENUM` declarado em quatro tabelas, e acrescentar um tipo são
     * quatro `ALTER TABLE` que têm de concordar.
     *
     * A discordância não dá erro: uma `whitelist` que aceite `bracelet` e um `capabilities`
     * que não o conheça deixam o dispositivo registado e sem capacidade nenhuma, calados. O
     * quinto sítio é o `DeviceMetadata`, em código, e é o que a lista abaixo compara.
     */
    public function testTheDeviceTypeEnumAgreesEverywhereItIsDeclared(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $tables = ['capabilities', 'models', 'supplier_device_types', 'whitelist'];
        $reference = DeviceMetadata::deviceTypes();

        foreach ($tables as $table) {
            $column = null;
            foreach ($this->columnStructure($pdo, $table) as $row) {
                if (($row['COLUMN_NAME'] ?? null) === 'device_type') {
                    $column = (string)$row['COLUMN_TYPE'];
                }
            }

            self::assertNotNull($column, "a tabela {$table} devia declarar a coluna device_type");
            self::assertSame(
                $reference,
                $this->enumValues($column),
                "o ENUM de {$table} divergiu da lista do DeviceMetadata",
            );
        }
    }

    /** @return list<string> */
    private function enumValues(string $columnType): array
    {
        if (preg_match("/^enum\((.*)\)$/i", $columnType, $matches) !== 1) {
            self::fail("a coluna device_type devia ser um ENUM, e é {$columnType}");
        }

        return array_map(
            static fn(string $value): string => trim($value, "'"),
            explode(',', $matches[1])
        );
    }

    public function testTheDroppedDiaperTableStaysDropped(): void
    {
        // A `2026082802` largou-a e o `schema.sql` recriava-a na execução seguinte. Este
        // caso concreto fica preso à parte do teste geral porque foi o que o revelou.
        $pdo = $this->createDashboardDatabase()->pdo();

        self::assertNotContains('diaper_sensor_settings', $this->tableNames($pdo));
    }
}
