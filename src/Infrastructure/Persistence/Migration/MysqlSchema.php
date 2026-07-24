<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class MysqlSchema
{
    public function __construct(private PDO $pdo)
    {
    }

    public function hasTable(string $table): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function hasIndex(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
        ');
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function addColumn(string $table, string $column, string $definition): void
    {
        if (!$this->hasColumn($table, $column)) {
            $this->pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    public function addIndex(string $table, string $index, string $columns): void
    {
        if (!$this->hasIndex($table, $index)) {
            $this->pdo->exec("CREATE INDEX `{$index}` ON `{$table}` ({$columns})");
        }
    }
}
