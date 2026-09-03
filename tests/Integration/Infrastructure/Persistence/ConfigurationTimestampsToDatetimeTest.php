<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\ConfigurationTimestampsToDatetime;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A conversão dos instantes de texto para `DATETIME`, sobre linhas que já existiam.
 *
 * O `MigrationTest` não testa o que cada migração faz, e com razão. Esta é a excepção pela
 * mesma razão que a do áudio: converte **dados** -- 358 linhas de produção --, corre uma vez
 * e não tem volta. Um valor que o `STR_TO_DATE` não reconheça sairia a zeros numa coluna
 * `NOT NULL`, em silêncio.
 *
 * Retira-se com a migração, quando ela sair do plano.
 */
final class ConfigurationTimestampsToDatetimeTest extends MysqlDashboardTestCase
{
    /** Devolve as onze colunas à forma anterior: `VARCHAR(32)`, com `''` a fazer de nulo. */
    private function databaseInTheOldShape(): PDO
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("ALTER TABLE device_configurations
            MODIFY desired_updated_at VARCHAR(32) NOT NULL DEFAULT '',
            MODIFY reported_at VARCHAR(32) NOT NULL DEFAULT '',
            MODIFY applied_at VARCHAR(32) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE device_configuration_changes
            MODIFY created_at VARCHAR(32) NOT NULL,
            MODIFY updated_at VARCHAR(32) NOT NULL,
            MODIFY confirmed_at VARCHAR(32) NOT NULL DEFAULT '',
            MODIFY superseded_at VARCHAR(32) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE device_configuration_operations
            MODIFY created_at VARCHAR(32) NOT NULL,
            MODIFY updated_at VARCHAR(32) NOT NULL,
            MODIFY sent_at VARCHAR(32) NOT NULL DEFAULT '',
            MODIFY acknowledged_at VARCHAR(32) NOT NULL DEFAULT ''");

        return $pdo;
    }

    /** A revisão entra como argumento: a chave única não deixa duas iguais no mesmo par. */
    private function insertChange(PDO $pdo, string $changeId, int $revision, string $confirmed, string $superseded): void
    {
        $pdo->prepare('
            INSERT INTO device_configuration_changes (
                change_id, imei, config_key, desired_revision, desired_payload,
                sync_status, created_at, updated_at, confirmed_at, superseded_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $changeId, '861728087056333', 'alarm_clock', $revision, '{}', 'confirmed',
            '2026-07-30T12:07:23Z', '2026-07-30T12:07:24Z', $confirmed, $superseded,
        ]);
    }

    private function value(PDO $pdo, string $table, string $column, string $id, string $key): mixed
    {
        $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE {$key} = ?");
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_NUM)[0] ?? null;
    }

    public function testIsoTextBecomesADatetimeAndTheEmptyStringBecomesNull(): void
    {
        $pdo = $this->databaseInTheOldShape();
        $this->insertChange($pdo, 'change-substituida', 1, '2026-07-30T13:00:00Z', '2026-07-30T14:30:59Z');
        $this->insertChange($pdo, 'change-corrente', 2, '', '');

        (new ConfigurationTimestampsToDatetime())->up($pdo);

        self::assertSame(
            '2026-07-30 12:07:23',
            $this->value($pdo, 'device_configuration_changes', 'created_at', 'change-corrente', 'change_id'),
            'o instante em ISO tem de sobreviver ao segundo',
        );
        self::assertNull(
            $this->value($pdo, 'device_configuration_changes', 'superseded_at', 'change-corrente', 'change_id'),
            'a cadeia vazia era "ainda não", e passa a NULL',
        );
        self::assertSame(
            '2026-07-30 14:30:59',
            $this->value($pdo, 'device_configuration_changes', 'superseded_at', 'change-substituida', 'change_id'),
        );
        self::assertSame(
            '2026-07-30 13:00:00',
            $this->value($pdo, 'device_configuration_changes', 'confirmed_at', 'change-substituida', 'change_id'),
        );
    }

    /** A condição que decide qual é a alteração corrente passa a `IS NULL`, e tem de acertar. */
    public function testTheCurrentChangeIsStillTheOneWithoutASupersededInstant(): void
    {
        $pdo = $this->databaseInTheOldShape();
        $this->insertChange($pdo, 'change-substituida', 1, '2026-07-30T13:00:00Z', '2026-07-30T14:30:59Z');
        $this->insertChange($pdo, 'change-corrente', 2, '', '');

        (new ConfigurationTimestampsToDatetime())->up($pdo);

        $correntes = $pdo
            ->query('SELECT change_id FROM device_configuration_changes WHERE superseded_at IS NULL')
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['change-corrente'], $correntes);
    }

    public function testAllElevenColumnsEndUpAsDatetimeAndRunningItTwiceIsSafe(): void
    {
        $pdo = $this->databaseInTheOldShape();

        (new ConfigurationTimestampsToDatetime())->up($pdo);
        (new ConfigurationTimestampsToDatetime())->up($pdo);

        $esperado = [
            'device_configurations' => ['desired_updated_at', 'reported_at', 'applied_at'],
            'device_configuration_changes' => ['created_at', 'updated_at', 'confirmed_at', 'superseded_at'],
            'device_configuration_operations' => ['created_at', 'updated_at', 'sent_at', 'acknowledged_at'],
        ];
        foreach ($esperado as $table => $columns) {
            foreach ($columns as $column) {
                $stmt = $pdo->prepare('
                    SELECT data_type FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
                ');
                $stmt->execute([$table, $column]);
                self::assertSame('datetime', $stmt->fetchColumn(), "{$table}.{$column} devia ser DATETIME");
            }
        }
    }

    /**
     * Um valor que não seja ISO faz a migração desistir, em vez de o `ALTER` o gravar a zeros.
     */
    public function testAValueThatIsNotIsoStopsTheMigration(): void
    {
        $pdo = $this->databaseInTheOldShape();
        $this->insertChange($pdo, 'change-torta', 1, '', '');
        $pdo->exec("UPDATE device_configuration_changes SET created_at = 'ontem' WHERE change_id = 'change-torta'");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('não são ISO-8601');

        (new ConfigurationTimestampsToDatetime())->up($pdo);
    }
}
