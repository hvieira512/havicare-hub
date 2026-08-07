<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Repairs the MKGW4 gateway capability flags.
 *
 * Version2026080701AddMkgw4Gateway used INSERT IGNORE, which is a no-op when a
 * model_capabilities row already exists. On installations that had already
 * seeded MKGW4 rows -- before DeviceProtocol knew about the moko-mkgw4 protocol
 * -- battery and location were left disabled even though the gateway reports
 * both. This migration updates the existing rows instead of ignoring them.
 */
final class Version2026080702EnableMkgw4GatewayCapabilities implements Migration
{
    public function version(): string
    {
        return '2026080702_enable_mkgw4_gateway_capabilities';
    }

    public function up(PDO $pdo): void
    {
        $seeder = new ReferenceCatalogSeeder();
        $seeder->seedReferenceData($pdo);
        $seeder->seedMissingModelCapabilities($pdo);

        // Insert whatever is missing...
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled)
            SELECT m.id, c.id, 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id AND s.name = 'MOKO'
            JOIN capabilities c ON c.device_type = 'gateway'
            WHERE m.internal_model = 'MKGW4'
              AND c.capability_key IN ('connectivity', 'battery', 'location')
        ");

        // ...then enable the rows that already existed with enabled = 0.
        $pdo->exec("
            UPDATE model_capabilities mc
            JOIN models m ON m.id = mc.model_id AND m.internal_model = 'MKGW4'
            JOIN suppliers s ON s.id = m.supplier_id AND s.name = 'MOKO'
            JOIN capabilities c ON c.id = mc.capability_id AND c.device_type = 'gateway'
            SET mc.enabled = 1
            WHERE c.capability_key IN ('connectivity', 'battery', 'location')
        ");
    }
}
