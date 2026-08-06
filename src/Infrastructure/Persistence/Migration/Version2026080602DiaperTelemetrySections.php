<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026080602DiaperTelemetrySections implements Migration
{
    public function version(): string
    {
        return '2026080602_diaper_telemetry_sections';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            UPDATE capabilities
            SET section = 'telemetry', updated_at = CURRENT_TIMESTAMP
            WHERE device_type = 'diaper_sensor'
              AND capability_key IN ('diaper_moisture', 'diaper_condition')
        ");
    }
}
