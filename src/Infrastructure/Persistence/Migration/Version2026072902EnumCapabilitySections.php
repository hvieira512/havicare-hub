<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026072902EnumCapabilitySections implements Migration
{
    public function version(): string
    {
        return '2026072902_enum_capability_sections';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            ALTER TABLE capabilities
            MODIFY section ENUM(
                'telemetry',
                'health',
                'contacts',
                'alarms',
                'settings_system'
            ) NOT NULL
        ");
    }
}
