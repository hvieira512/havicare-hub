<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * O radar da Qinglanst toma o nome que a cloud lhe dá no `getDeviceInfo` (`modelNumber` 2, HC2).
 * O nome comercial fica.
 */
final class QinglanstRadarIsHc2 implements Migration
{
    public function version(): string
    {
        return '2026_10_16_qinglanst_radar_is_hc2';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            UPDATE models m JOIN suppliers s ON s.id = m.supplier_id
            SET m.internal_model = 'HC2'
            WHERE s.name = 'Qinglanst' AND m.internal_model = 'RD-V1'
              AND NOT EXISTS (
                  SELECT 1 FROM (SELECT supplier_id FROM models WHERE internal_model = 'HC2') hc2
                  WHERE hc2.supplier_id = m.supplier_id
              )
        ");
        $pdo->exec("UPDATE whitelist SET model = 'HC2' WHERE supplier = 'Qinglanst' AND model = 'RD-V1'");
    }
}
