<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\DeviceTypeAsciiCollation;
use Hub\Infrastructure\Persistence\Migration\DeviceTypesTable;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * As duas migrações do `device_type` têm de convergir a partir da forma `ENUM`.
 *
 * O `schema.sql` corre antes delas e cria a `device_types` já em `ascii_bin`. Uma base que
 * venha do estado anterior tem as outras quatro colunas em `utf8mb4`, e uma chave estrangeira
 * exige colação igual nos dois lados -- é a combinação que uma instalação restaurada de uma
 * cópia antiga encontra.
 */
final class DeviceTypeMigrationConvergenceTest extends MysqlDashboardTestCase
{
    private const TABLES = ['device_types', 'whitelist', 'models', 'capabilities', 'model_capabilities'];

    private const CONSTRAINTS = [
        'whitelist' => 'fk_whitelist_device_type',
        'models' => 'fk_models_device_type',
        'capabilities' => 'fk_capabilities_device_type',
        'model_capabilities' => 'fk_model_capabilities_capability_v3',
    ];

    /** Devolve as quatro colunas à forma anterior e retira as chaves que delas dependiam. */
    private function databaseInTheEnumShape(): PDO
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        foreach (self::CONSTRAINTS as $table => $constraint) {
            $pdo->exec("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}");
        }

        $enum = "ENUM('watch','ncs','radar','gateway','diaper_sensor','bracelet')"
            . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'watch'";
        $pdo->exec("ALTER TABLE whitelist MODIFY device_type {$enum}");
        $pdo->exec("ALTER TABLE models MODIFY device_type {$enum}");
        $pdo->exec("ALTER TABLE capabilities MODIFY device_type {$enum}");
        $pdo->exec('ALTER TABLE model_capabilities MODIFY device_type '
            . 'VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');

        return $pdo;
    }

    private function collation(PDO $pdo, string $table): string
    {
        $stmt = $pdo->prepare('
            SELECT collation_name FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute([$table, 'device_type']);

        return (string)$stmt->fetchColumn();
    }

    private function hasConstraint(PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?
        ');
        $stmt->execute([$table, $constraint]);

        return (int)$stmt->fetchColumn() > 0;
    }

    public function testTheTwoMigrationsConvergeFromTheEnumShape(): void
    {
        $pdo = $this->databaseInTheEnumShape();

        (new DeviceTypesTable())->up($pdo);
        (new DeviceTypeAsciiCollation())->up($pdo);

        foreach (self::TABLES as $table) {
            self::assertSame('ascii_bin', $this->collation($pdo, $table), "{$table}.device_type");
        }

        foreach (self::CONSTRAINTS as $table => $constraint) {
            self::assertTrue($this->hasConstraint($pdo, $table, $constraint), $constraint);
        }
    }

    /**
     * O estado que uma tentativa falhada deixava: o `ENUM` já convertido para `VARCHAR` em
     * `utf8mb4`, e a chave ainda sem nascer. Sem convergir daqui, a base ficava presa -- a
     * coluna já não é `ENUM`, e a segunda tentativa dava o mesmo erro que a primeira.
     */
    public function testTheMigrationsConvergeFromAHalfConvertedColumn(): void
    {
        $pdo = $this->databaseInTheEnumShape();
        $pdo->exec("ALTER TABLE whitelist MODIFY device_type "
            . "VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'watch'");

        (new DeviceTypesTable())->up($pdo);
        (new DeviceTypeAsciiCollation())->up($pdo);

        self::assertSame('ascii_bin', $this->collation($pdo, 'whitelist'));
        self::assertTrue($this->hasConstraint($pdo, 'whitelist', 'fk_whitelist_device_type'));
    }

    /**
     * Correr as duas outra vez não pode mexer em nada. O plano corre-as em cada arranque, e
     * uma migração que só converge à primeira deixa a base presa quando falha a meio.
     */
    public function testRunningBothMigrationsAgainChangesNothing(): void
    {
        $pdo = $this->databaseInTheEnumShape();

        (new DeviceTypesTable())->up($pdo);
        (new DeviceTypeAsciiCollation())->up($pdo);
        (new DeviceTypesTable())->up($pdo);
        (new DeviceTypeAsciiCollation())->up($pdo);

        foreach (self::TABLES as $table) {
            self::assertSame('ascii_bin', $this->collation($pdo, $table), "{$table}.device_type");
        }

        foreach (self::CONSTRAINTS as $table => $constraint) {
            self::assertTrue($this->hasConstraint($pdo, $table, $constraint), $constraint);
        }
    }
}
