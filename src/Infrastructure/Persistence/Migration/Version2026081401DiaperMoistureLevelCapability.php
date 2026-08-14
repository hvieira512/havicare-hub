<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Gives the diaper sensor its `diaper_moisture_level` capability.
 *
 * Two steps, and both are needed. syncCapabilities upserts the row in
 * `capabilities` from the catalog, which is what makes the card exist at all.
 * But seedModelCapabilities SKIPS any model that already has rows, so on an
 * installation where MECS-PRO was seeded before today the model would never
 * gain the capability and the dashboard would keep hiding the card -- the same
 * gap that 2026081102_backfill_missing_model_capabilities was written to close.
 * Hence the explicit insert for the models whose protocol declares it.
 *
 * INSERT IGNORE, and no UPDATE: a capability someone switched off by hand keeps
 * its row and stays off. This fills a gap, it does not re-enable anything.
 */
final class Version2026081401DiaperMoistureLevelCapability implements Migration
{
    public function version(): string
    {
        return '2026081401_diaper_moisture_level_capability';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->syncCapabilities($pdo);

        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled)
            SELECT m.id, c.id, 1
            FROM models m
            JOIN capabilities c
              ON c.device_type = m.device_type
             AND c.capability_key = 'diaper_moisture_level'
            WHERE m.device_type = 'diaper_sensor'
        ");
    }
}
