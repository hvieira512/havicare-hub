<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\DatabaseMigrator;
use Hub\Infrastructure\Persistence\Migration\CatalogAlarmProximityAndHelpCall;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A primeira migração desde a baseline: traz a tabela `capabilities` ao que o código declara.
 *
 * O que se prova aqui é o caminho, e não o destino -- o destino de uma base nova está no
 * `ReferenceCatalogTest`. Uma base que já existia tem de chegar ao mesmo sítio sem perder o
 * que lá estava.
 */
final class CatalogAlarmProximityAndHelpCallTest extends MysqlDashboardTestCase
{
    /**
     * Uma base como estava antes: `pager_call` no NCS, e sem `alarm` nem `proximity`.
     *
     * Constrói-se desfazendo, sobre uma base já migrada, exactamente o que a migração faz.
     */
    private function rollBackToPreMigrationState(PDO $pdo): void
    {
        $pdo->exec("UPDATE capabilities SET capability_key = 'pager_call' WHERE device_type = 'ncs' AND capability_key = 'help_call'");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE (device_type = 'watch' AND capability_key = 'alarm')
               OR (device_type IN ('bracelet', 'diaper_sensor') AND capability_key = 'proximity')
        ");
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '2026_09_01_catalog_alarm_proximity_help_call'");
    }

    /** @return list<string> */
    private function keys(PDO $pdo, string $deviceType): array
    {
        $stmt = $pdo->prepare('SELECT capability_key FROM capabilities WHERE device_type = ? ORDER BY capability_key');
        $stmt->execute([$deviceType]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testItAddsTheMissingCapabilitiesToADatabaseThatAlreadyExisted(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->rollBackToPreMigrationState($pdo);

        self::assertNotContains('alarm', $this->keys($pdo, 'watch'), 'o estado inicial tem de ser o de antes');
        self::assertNotContains('proximity', $this->keys($pdo, 'bracelet'));

        (new DatabaseMigrator($pdo))->migrate();

        self::assertContains('alarm', $this->keys($pdo, 'watch'));
        self::assertContains('proximity', $this->keys($pdo, 'bracelet'));
        self::assertContains('proximity', $this->keys($pdo, 'diaper_sensor'));
    }

    /**
     * O `id` da capacidade tem de sobreviver ao renomear.
     *
     * É por ele que o `model_capabilities` liga o Voerka W812 à chamada de ajuda. Apagar e
     * recriar a linha levava essa ligação por arrasto, e o modelo ficava sem a capacidade que
     * o aparelho tem.
     */
    public function testRenamingPagerCallKeepsTheIdAndTheModelLink(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->rollBackToPreMigrationState($pdo);

        $before = (int)$pdo->query("SELECT id FROM capabilities WHERE device_type = 'ncs' AND capability_key = 'pager_call'")->fetchColumn();
        self::assertGreaterThan(0, $before);
        $linkedModels = (int)$pdo->query("SELECT COUNT(*) FROM model_capabilities WHERE capability_id = {$before}")->fetchColumn();
        self::assertGreaterThan(0, $linkedModels, 'o W812 tem de estar ligado antes');

        (new DatabaseMigrator($pdo))->migrate();

        $after = (int)$pdo->query("SELECT id FROM capabilities WHERE device_type = 'ncs' AND capability_key = 'help_call'")->fetchColumn();
        self::assertSame($before, $after);
        self::assertSame(
            $linkedModels,
            (int)$pdo->query("SELECT COUNT(*) FROM model_capabilities WHERE capability_id = {$after}")->fetchColumn()
        );
        self::assertSame(
            0,
            (int)$pdo->query("SELECT COUNT(*) FROM capabilities WHERE device_type = 'ncs' AND capability_key = 'pager_call'")->fetchColumn()
        );
    }

    /** Correr outra vez não muda nada. */
    public function testItIsIdempotent(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->rollBackToPreMigrationState($pdo);

        (new DatabaseMigrator($pdo))->migrate();
        $first = $pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn();

        $pdo->exec("DELETE FROM schema_migrations WHERE version = '2026_09_01_catalog_alarm_proximity_help_call'");
        (new DatabaseMigrator($pdo))->migrate();

        self::assertSame($first, $pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn());
    }

    /**
     * Numa base vazia a migração não pode escrever nada.
     *
     * O `DatabaseMigrator` corre as migrações **antes** do semeador de referência, e esse só
     * corre quando a tabela `capabilities` está vazia. Uma migração que escrevesse aqui fazia
     * a guarda saltar, e uma instalação de raiz nascia sem fornecedores nem modelos.
     */
    public function testItDoesNothingOnAnEmptyDatabaseSoTheBaselineSeederStillRuns(): void
    {
        $pdo = $this->pdoForDatabase($this->createEmptyDatabase());
        $pdo->exec((string)file_get_contents(__DIR__ . '/../../../../database/schema.sql'));

        (new CatalogAlarmProximityAndHelpCall())->up($pdo);

        self::assertSame(
            0,
            (int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn(),
            'a migração escreveu numa base vazia e vai fazer o semeador saltar'
        );
    }

    /** E o caminho completo numa base de raiz chega ao mesmo sítio. */
    public function testAFreshDatabaseStillGetsSuppliersModelsAndTheNewCapabilities(): void
    {
        $pdo = $this->pdoForDatabase($this->createEmptyDatabase());

        (new DatabaseMigrator($pdo))->migrate();

        self::assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn());
        self::assertGreaterThan(0, (int)$pdo->query('SELECT COUNT(*) FROM models')->fetchColumn());
        self::assertContains('alarm', $this->keys($pdo, 'watch'));
        self::assertContains('proximity', $this->keys($pdo, 'bracelet'));
        self::assertContains('help_call', $this->keys($pdo, 'ncs'));
    }
}
