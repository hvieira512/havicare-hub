<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Introduces the bracelet device type and the MOKO W6R.
 *
 * The W6R is a BLE button relayed by a MOKO gateway, so it is linked to one or
 * more gateways exactly like the MONIT diaper sensor.
 */
final class Version2026081001BraceletDevices implements Migration
{
    public function version(): string
    {
        return '2026081001_bracelet_devices';
    }

    public function up(PDO $pdo): void
    {
        DeviceTypeColumn::widen($pdo);

        $seeder = new ReferenceCatalogSeeder();
        $seeder->seedReferenceData($pdo);
        $seeder->seedMissingModelCapabilities($pdo);

        // seedMissingModelCapabilities only seeds models that have no rows at
        // all, and INSERT IGNORE cannot repair a row that already exists, so
        // enable the W6R capabilities explicitly.
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled)
            SELECT m.id, c.id, 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id AND s.name = 'MOKO'
            JOIN capabilities c ON c.device_type = 'bracelet'
            WHERE m.internal_model = 'W6R'
              AND c.capability_key IN ('battery', 'motion', 'help_call')
        ");
        $pdo->exec("
            UPDATE model_capabilities mc
            JOIN models m ON m.id = mc.model_id AND m.internal_model = 'W6R'
            JOIN suppliers s ON s.id = m.supplier_id AND s.name = 'MOKO'
            JOIN capabilities c ON c.id = mc.capability_id AND c.device_type = 'bracelet'
            SET mc.enabled = 1
            WHERE c.capability_key IN ('battery', 'motion', 'help_call')
        ");
    }
}
