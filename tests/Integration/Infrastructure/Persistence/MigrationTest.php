<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\DashboardDatabase;
use Hub\Infrastructure\Persistence\DatabaseMigrationPlan;
use Hub\Infrastructure\Persistence\DatabaseSchemaGuard;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A mecânica das migrações: correm uma vez, ficam registadas, e o guarda sabe dizer se
 * falta alguma.
 *
 * O que cada migração fez à base de dados deixou de se testar aqui. Trinta e oito delas
 * foram absorvidas na baseline -- o `schema.sql` mais o catálogo semeado a partir do
 * código -- e o estado que produziam é agora afirmado onde ele vive: a estrutura no
 * `SchemaCompletenessTest`, o catálogo no `ReferenceCatalogTest`. Testar a migração era
 * testar o caminho; o que interessa é o destino.
 */
final class MigrationTest extends MysqlDashboardTestCase
{
    public function testDatabaseConnectionDoesNotApplySchemaOrMigrations(): void
    {
        $databaseName = $this->createEmptyDatabase();
        $database = new DashboardDatabase($this->dashboardDatabaseConfig($databaseName));

        self::assertSame([], $database->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('php bin/migrate.php');
        (new DatabaseSchemaGuard($database->pdo()))->assertCurrent();
    }

    public function testApplicationDatabaseSessionUsesUtc(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        self::assertSame('+00:00', $pdo->query('SELECT @@session.time_zone')->fetchColumn());
        self::assertSame(
            $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn(),
            $pdo->query('SELECT NOW()')->fetchColumn(),
        );
    }

    public function testEveryPlannedMigrationIsRecordedAndDoesNotRerun(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $planned = (new DatabaseMigrationPlan())->versions();

        $recorded = array_map('strval', $pdo
            ->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame($planned, $recorded);

        // Correr outra vez não duplica nem volta a executar: é o registo que decide.
        $this->reopenDashboardDatabase($this->databaseName($pdo));
        self::assertSame(
            count($planned),
            (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()
        );

        (new DatabaseSchemaGuard($pdo))->assertCurrent();
    }
}
