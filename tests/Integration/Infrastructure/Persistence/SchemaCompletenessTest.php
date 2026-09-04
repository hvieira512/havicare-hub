<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Domain\DeviceTypeCatalog;
use PDO;
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
     * O `device_type` deixou de ser um `ENUM` repetido em três tabelas e passou a referência
     * para a `device_types`, cujo conteúdo vem do `config/device-types.json`.
     *
     * O que este caso prende é o que sobra por prender: a tabela tem de reproduzir o ficheiro
     * -- o `DeviceTypeCatalog` é servido ao frontend, e uma linha a mais ou a menos aqui
     * deixava passar um tipo que o ecrã não conhece --, e as três chaves estrangeiras têm de
     * existir, senão a integridade que substituiu o `ENUM` não existe.
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
     * O `device_type` distingue maiúsculas, e um tipo mal escrito é recusado.
     *
     * A coluna está em `ascii_bin`, que compara byte a byte: é a colação mais rápida para
     * identificadores ASCII, e o `key_len` do índice cai de 135 bytes para 39. O efeito
     * secundário é este, e é desejado -- com uma colação `_ci`, um `Watch` casava em silêncio
     * com o `watch` do catálogo, e o valor errado entrava na tabela.
     *
     * Os dez caminhos de escrita passam todos pelo `DeviceMetadata::normalizeDeviceType()`,
     * que faz `strtolower`, pelo que nada de legítimo chega aqui em maiúsculas.
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
        // A `2026082802` largou-a e o `schema.sql` recriava-a na execução seguinte. Este
        // caso concreto fica preso à parte do teste geral porque foi o que o revelou.
        $pdo = $this->createDashboardDatabase()->pdo();

        self::assertNotContains('diaper_sensor_settings', $this->tableNames($pdo));
    }
}
