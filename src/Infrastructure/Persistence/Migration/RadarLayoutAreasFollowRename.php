<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/** As áreas da planta seguem o IMEI renomeado, como a planta já seguia a `whitelist`. */
final class RadarLayoutAreasFollowRename implements Migration
{
    public function version(): string
    {
        return '2026_10_27_radar_layout_areas_follow_rename';
    }

    public function up(PDO $pdo): void
    {
        $rule = $pdo->query("
            SELECT UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_radar_layout_areas_layout'
        ")->fetchColumn();
        if ($rule === 'CASCADE') {
            return;
        }

        $pdo->exec('ALTER TABLE radar_layout_areas DROP FOREIGN KEY fk_radar_layout_areas_layout');
        $pdo->exec('
            ALTER TABLE radar_layout_areas ADD CONSTRAINT fk_radar_layout_areas_layout FOREIGN KEY (imei)
                REFERENCES radar_layouts(imei) ON DELETE CASCADE ON UPDATE CASCADE
        ');
    }
}
