<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\DropUnreadColumns;
use Tests\Support\MysqlDashboardTestCase;

/** As colunas escritas e nunca lidas saem de uma base que as tem, e uma base nova nasce sem elas. */
final class DropUnreadColumnsTest extends MysqlDashboardTestCase
{
    private const COLUMNS = [
        ['radar_api_credentials', 'access_token', "VARCHAR(512) NOT NULL DEFAULT ''"],
        ['radar_api_credentials', 'refresh_token', "VARCHAR(512) NOT NULL DEFAULT ''"],
        ['radar_api_credentials', 'token_expires_at', 'DATETIME NULL DEFAULT NULL'],
        ['device_configuration_operations', 'max_attempts', 'INT UNSIGNED NOT NULL DEFAULT 3'],
        ['radar_layouts', 'source_payload', 'LONGTEXT NOT NULL'],
        ['device_configurations', 'last_error', "VARCHAR(64) NOT NULL DEFAULT ''"],
    ];

    public function testAFreshDatabaseHasNoneOfThem(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        foreach (self::COLUMNS as [$table, $column]) {
            self::assertFalse($this->hasColumn($pdo, $table, $column), "{$table}.{$column} não devia existir");
        }
    }

    public function testAnOlderDatabaseLosesThem(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        foreach (self::COLUMNS as [$table, $column, $definition]) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }

        (new DropUnreadColumns())->up($pdo);
        (new DropUnreadColumns())->up($pdo);

        foreach (self::COLUMNS as [$table, $column]) {
            self::assertFalse($this->hasColumn($pdo, $table, $column), "{$table}.{$column} devia ter saído");
        }
    }

    private function hasColumn(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ');
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
