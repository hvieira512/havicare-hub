<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A planta de cada radar: a divisão e as áreas declaradas no aparelho.
 *
 * Caixas e não polígonos, porque é isso que o fabricante declara na prática, e uma linha por
 * radar porque o que ele devolve é o layout de agora.
 */
final class RadarLayouts implements Migration
{
    public function version(): string
    {
        return '2026_09_17_radar_layouts';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS radar_layouts (
                imei VARCHAR(64) NOT NULL PRIMARY KEY,
                room_x_min_dm SMALLINT NOT NULL,
                room_y_min_dm SMALLINT NOT NULL,
                room_x_max_dm SMALLINT NOT NULL,
                room_y_max_dm SMALLINT NOT NULL,
                source_payload LONGTEXT NOT NULL,
                fetched_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_radar_layouts_device FOREIGN KEY (imei)
                    REFERENCES whitelist(imei) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS radar_layout_areas (
                imei VARCHAR(64) NOT NULL,
                area_key TINYINT UNSIGNED NOT NULL,
                area_type TINYINT UNSIGNED NOT NULL,
                name VARCHAR(64) NOT NULL,
                x_min_dm SMALLINT NOT NULL,
                y_min_dm SMALLINT NOT NULL,
                x_max_dm SMALLINT NOT NULL,
                y_max_dm SMALLINT NOT NULL,
                PRIMARY KEY (imei, area_key),
                CONSTRAINT fk_radar_layout_areas_layout FOREIGN KEY (imei)
                    REFERENCES radar_layouts(imei) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
