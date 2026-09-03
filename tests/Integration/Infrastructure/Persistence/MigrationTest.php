<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\DashboardDatabase;
use Hub\Infrastructure\Persistence\DatabaseMigrationPlan;
use Hub\Infrastructure\Persistence\DatabaseSchemaGuard;
use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\MigrationRunner;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A mecânica das migrações: correm uma vez, ficam registadas, e o guarda sabe dizer se
 * falta alguma.
 *
 * O que cada migração faz à base de dados não se testa aqui. O estado que a baseline produz
 * é afirmado onde ele vive: a estrutura no `SchemaCompletenessTest`, o catálogo no
 * `ReferenceCatalogTest`. Testar a migração é testar o caminho; o que interessa é o destino.
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

    /**
     * A migração é de mentira de propósito. Com as reais, este teste passava a afirmar o
     * conteúdo do plano em vez do comportamento do motor -- e ficava vazio de cada vez que
     * as migrações aplicadas nas duas bases fossem reformadas, que é o ciclo normal delas.
     */
    public function testAMigrationRunsOnceAndTheLedgerDecides(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $migration = new class implements Migration {
            public int $runs = 0;

            public function version(): string
            {
                return 'teste_corre_uma_vez';
            }

            public function up(\PDO $pdo): void
            {
                $this->runs++;
            }
        };

        (new MigrationRunner($pdo, [$migration]))->run();
        (new MigrationRunner($pdo, [$migration]))->run();

        self::assertSame(1, $migration->runs, 'a segunda passagem não pode voltar a executá-la');
        self::assertSame(
            1,
            (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 'teste_corre_uma_vez'")
                ->fetchColumn(),
            'o registo tem uma linha por migração, e não uma por passagem',
        );
    }

    /** O guarda compara o plano com o registo, e uma base acabada de migrar está em dia. */
    public function testAFreshlyMigratedDatabaseSatisfiesTheGuard(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        // Comparados como conjuntos: a ordem do plano é a de execução, e nada obriga a que
        // ela coincida com a alfabética do registo. O que se afirma é que ficou tudo lá.
        $planned = (new DatabaseMigrationPlan())->versions();
        sort($planned);
        $recorded = array_map('strval', $pdo
            ->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame($planned, $recorded);

        (new DatabaseSchemaGuard($pdo))->assertCurrent();
    }
}
