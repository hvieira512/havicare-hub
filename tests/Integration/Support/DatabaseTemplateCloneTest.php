<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use Tests\Support\MysqlDashboardTestCase;

/**
 * Guarda o próprio arnês de testes.
 *
 * Os testes recebem uma base clonada de um modelo e não uma acabada de migrar, e por isso o
 * clone tem de ser indistinguível do esquema a sério.
 */
final class DatabaseTemplateCloneTest extends MysqlDashboardTestCase
{
    public function testCloneKeepsForeignKeysWithTheirCascadeRules(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $schema = $this->databaseName($pdo);

        $constraints = $pdo->query("
            SELECT rc.constraint_name, rc.delete_rule
            FROM information_schema.referential_constraints rc
            WHERE rc.constraint_schema = '{$schema}'
              AND rc.table_name = 'gateway_device_links'
            ORDER BY rc.constraint_name
        ")->fetchAll(\PDO::FETCH_KEY_PAIR);

        // O `CREATE TABLE ... LIKE` largava estas em silêncio.
        self::assertSame([
            'fk_gateway_device_links_device' => 'CASCADE',
            'fk_gateway_device_links_gateway' => 'CASCADE',
        ], $constraints);
    }

    public function testCloneCarriesTheSeededReferenceCatalogAndMigrationHistory(): void
    {
        $db = $this->createDashboardDatabase();
        $pdo = $db->pdo();

        self::assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM models')->fetchColumn());
        self::assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn());
        self::assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }

    public function testEachTestGetsAnIsolatedDatabase(): void
    {
        $first = $this->createDashboardDatabase();
        $second = $this->createDashboardDatabase();

        self::assertNotSame($this->databaseName($first->pdo()), $this->databaseName($second->pdo()));

        $first->pdo()->exec("DELETE FROM models");

        self::assertSame(0, (int)$first->pdo()->query('SELECT COUNT(*) FROM models')->fetchColumn());
        self::assertGreaterThan(0, (int)$second->pdo()->query('SELECT COUNT(*) FROM models')->fetchColumn());
    }
}
