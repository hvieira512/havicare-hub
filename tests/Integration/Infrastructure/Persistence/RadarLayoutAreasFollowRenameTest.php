<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\RadarLayoutAreasFollowRename;
use Tests\Support\MysqlDashboardTestCase;

/** Uma base com a chave antiga passa a levar as áreas quando o IMEI muda. */
final class RadarLayoutAreasFollowRenameTest extends MysqlDashboardTestCase
{
    public function testTheAreasForeignKeyCascadesOnUpdate(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec('ALTER TABLE radar_layout_areas DROP FOREIGN KEY fk_radar_layout_areas_layout');
        $pdo->exec('
            ALTER TABLE radar_layout_areas ADD CONSTRAINT fk_radar_layout_areas_layout FOREIGN KEY (imei)
                REFERENCES radar_layouts(imei) ON DELETE CASCADE
        ');

        (new RadarLayoutAreasFollowRename())->up($pdo);
        (new RadarLayoutAreasFollowRename())->up($pdo);

        self::assertSame('CASCADE', $pdo->query("
            SELECT UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_radar_layout_areas_layout'
        ")->fetchColumn());
    }
}
