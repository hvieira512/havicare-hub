<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Domain\DeviceTypeCatalog;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * O `database/schema.sql` corre antes das migrações e tem de concordar com elas: divergindo,
 * recria o que uma migração largou, ou falta numa base nova quando a migração for apagada.
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
     * O `device_type` referencia a `device_types`, que tem de reproduzir o
     * `config/device-types.json`, e as três chaves estrangeiras têm de existir.
     */
    public function testEveryDeviceTypeColumnPointsAtTheCatalogTable(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        $stored = $pdo->query('SELECT device_type FROM device_types ORDER BY device_type')
            ->fetchAll(PDO::FETCH_COLUMN);
        $expected = DeviceTypeCatalog::keys();
        sort($expected);
        self::assertSame($expected, $stored, 'a device_types tem de reproduzir o device-types.json');

        foreach (['capabilities', 'models', 'whitelist'] as $table) {
            $stmt = $pdo->prepare('
                SELECT COUNT(*) FROM information_schema.key_column_usage
                WHERE table_schema = DATABASE() AND table_name = ?
                  AND column_name = ? AND referenced_table_name = ?
            ');
            $stmt->execute([$table, 'device_type', 'device_types']);
            self::assertSame(
                1,
                (int)$stmt->fetchColumn(),
                "o {$table}.device_type devia referenciar a device_types",
            );
        }
    }

    /** A chave estrangeira recusa um tipo que a tabela não conheça. */
    public function testADeviceTypeOutsideTheCatalogIsRefused(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        $this->expectException(\PDOException::class);
        $pdo->prepare('INSERT INTO whitelist (imei, supplier, model, device_type) VALUES (?, ?, ?, ?)')
            ->execute(['999999999999999', 'Vivistar', 'L08 Pro', 'torradeira']);
    }

    /**
     * A coluna está em `ascii_bin`, que compara byte a byte: um `Watch` não casa com o `watch`.
     * Os caminhos de escrita passam todos pelo `normalizeDeviceType()`, que faz `strtolower`.
     */
    public function testADeviceTypeInTheWrongCaseIsRefused(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        $this->expectException(\PDOException::class);
        $pdo->prepare('INSERT INTO whitelist (imei, supplier, model, device_type) VALUES (?, ?, ?, ?)')
            ->execute(['999999999999998', 'Vivistar', 'L08 Pro', 'Watch']);
    }

    /** A colação das cinco colunas tem de ser a mesma, ou as chaves estrangeiras não nascem. */
    public function testEveryDeviceTypeColumnSharesTheSameCollation(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        $stmt = $pdo->query("
            SELECT DISTINCT collation_name FROM information_schema.columns
            WHERE table_schema = DATABASE() AND column_name = 'device_type'
        ");

        self::assertSame(['ascii_bin'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testTheDroppedDiaperTableStaysDropped(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        self::assertNotContains('diaper_sensor_settings', $this->tableNames($pdo));
    }
}
