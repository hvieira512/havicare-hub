<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026082101DiaperSensorSettings implements Migration
{
    public function version(): string
    {
        return '2026082101_diaper_sensor_settings';
    }

    public function up(PDO $pdo): void
    {
        // Sem backfill de propósito: a ausência de linha significa o preset normal,
        // que é exactamente o comportamento com que os sensores já registados
        // correm hoje. Inserir uma linha por sensor só criaria estado a manter para
        // dizer aquilo que a omissão já diz.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS diaper_sensor_settings (
                imei VARCHAR(64) NOT NULL PRIMARY KEY,
                pollution_range TINYINT UNSIGNED NOT NULL,
                pollution_value TINYINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_diaper_sensor_settings_device FOREIGN KEY (imei)
                    REFERENCES whitelist(imei) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
