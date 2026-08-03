<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026080301EnableWonlexPushMessage implements Migration
{
    public function version(): string
    {
        return '2026080301_enable_wonlex_push_message';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->seedReferenceData($pdo);

        $pdo->exec("
            INSERT INTO model_capabilities (model_id, capability_id, enabled)
            SELECT m.id, c.id, 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            JOIN capabilities c
              ON c.device_type = m.device_type
             AND c.capability_key = 'push_message'
            WHERE s.name = 'Wonlex'
              AND m.device_type = 'watch'
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)
        ");
    }
}
