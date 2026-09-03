<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Larga três coisas que eram escritas e nunca lidas.
 *
 * O `imei` e o `config_key` da `device_configuration_operations` copiavam a alteração a que a
 * operação pertence. A única leitura da tabela é por `change_id`, e as duas actualizações de
 * estado chegam ao IMEI pelo `JOIN` à alteração -- a cópia nunca era consultada, e um
 * `INSERT` podia pô-la em contradição com a linha-mãe sem que nada acusasse.
 *
 * O `confirmed_revision` da `device_configurations` era escrito no `stage()` e ao confirmar, e
 * não era lido em lado nenhum nem exposto na API. A pergunta que o par de revisões existe para
 * responder é respondida pelo `sync_status` da alteração.
 *
 * E o `idx_dashboard_notifications_unread` perde a segunda coluna: a consulta que o usa é
 * `WHERE read_at IS NULL`, sem ordenação.
 */
final class DropUnreadLifecycleColumns implements Migration
{
    private const DROPPED_COLUMNS = [
        'device_configuration_operations' => ['imei', 'config_key'],
        'device_configurations' => ['confirmed_revision'],
    ];

    public function version(): string
    {
        return '2026_09_04_drop_unread_lifecycle_columns';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::DROPPED_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->hasColumn($pdo, $table, $column)) {
                    $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
                }
            }
        }

        if ($this->indexColumns($pdo, 'dashboard_notifications', 'idx_dashboard_notifications_unread') > 1) {
            $pdo->exec('DROP INDEX idx_dashboard_notifications_unread ON dashboard_notifications');
        }
        if ($this->indexColumns($pdo, 'dashboard_notifications', 'idx_dashboard_notifications_unread') === 0) {
            $pdo->exec('CREATE INDEX idx_dashboard_notifications_unread ON dashboard_notifications (read_at)');
        }
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function indexColumns(PDO $pdo, string $table, string $index): int
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
        ');
        $stmt->execute([$table, $index]);

        return (int)$stmt->fetchColumn();
    }
}
