<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/** A coluna `capabilities.section` ganha a secção «Lembretes», que separa os despertadores dos alarmes. */
final class CapabilitySectionsIncludeReminders implements Migration
{
    public function version(): string
    {
        return 'capability_sections_include_reminders';
    }

    public function up(PDO $pdo): void
    {
        $column = $pdo->query("SHOW COLUMNS FROM capabilities LIKE 'section'")->fetch(PDO::FETCH_ASSOC);
        if (is_array($column) && str_contains((string)$column['Type'], "'reminders'")) {
            return;
        }

        $pdo->exec(
            "ALTER TABLE capabilities MODIFY section "
            . "ENUM('telemetry', 'health', 'contacts', 'alarms', 'reminders', 'settings_system') NOT NULL"
        );
    }
}
