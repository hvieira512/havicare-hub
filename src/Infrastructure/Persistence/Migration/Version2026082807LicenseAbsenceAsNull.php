<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A ausência de licença passa a NULL nas bases que já existem, onde era o inteiro 0 e, no
 * `whitelist`, a string literal `'null'`.
 *
 * A sentinela continua a existir onde o protocolo a exige -- o tópico MQTT é um caminho de
 * texto e um campo de hash do Redis só guarda strings --, mas construída na fronteira em vez
 * de guardada. O `ALTER` antes do `UPDATE` em cada tabela, porque a coluna tem de aceitar
 * NULL primeiro.
 *
 * A `company` nova das notificações fica a NULL nas linhas antigas: é o que se sabia delas.
 */
final class Version2026082807LicenseAbsenceAsNull implements Migration
{
    public function version(): string
    {
        return '2026082807_license_absence_as_null';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE whitelist
            MODIFY license_id INT UNSIGNED NULL DEFAULT NULL,
            MODIFY company VARCHAR(191) NULL DEFAULT NULL');
        $pdo->exec('UPDATE whitelist SET license_id = NULL WHERE license_id = 0');
        $pdo->exec("UPDATE whitelist SET company = NULL WHERE company = 'null' OR company = ''");

        $pdo->exec('ALTER TABLE api_users MODIFY license_id INT UNSIGNED NULL DEFAULT NULL');
        $pdo->exec('UPDATE api_users SET license_id = NULL WHERE license_id = 0');

        $pdo->exec('ALTER TABLE dashboard_notifications MODIFY license_id INT UNSIGNED NULL DEFAULT NULL');
        if (!self::hasColumn($pdo, 'dashboard_notifications', 'company')) {
            $pdo->exec('ALTER TABLE dashboard_notifications
                ADD COLUMN company VARCHAR(191) NULL DEFAULT NULL AFTER license_id');
        }
        $pdo->exec('UPDATE dashboard_notifications SET license_id = NULL WHERE license_id = 0');
    }

    /**
     * O `ADD COLUMN IF NOT EXISTS` é do MariaDB e o MySQL rejeita-o, e o `schema.sql` corre
     * antes desta migração: numa base nova a coluna já lá está.
     */
    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
