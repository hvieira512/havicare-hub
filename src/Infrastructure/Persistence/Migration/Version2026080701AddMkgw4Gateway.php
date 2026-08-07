<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026080701AddMkgw4Gateway implements Migration
{
    public function version(): string
    {
        return '2026080701_add_mkgw4_gateway';
    }

    public function up(PDO $pdo): void
    {
        $seeder = new ReferenceCatalogSeeder();
        $seeder->seedReferenceData($pdo);
        $seeder->seedMissingModelCapabilities($pdo);
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled)
            SELECT m.id, c.id, 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id AND s.name = 'MOKO'
            JOIN capabilities c ON c.device_type = 'gateway'
            WHERE m.internal_model = 'MKGW4'
              AND c.capability_key IN ('connectivity', 'battery', 'location')
        ");
    }
}
