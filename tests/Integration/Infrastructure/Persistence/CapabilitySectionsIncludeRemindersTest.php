<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\CapabilitySectionsIncludeReminders;
use Tests\Support\MysqlDashboardTestCase;

/** A secção «Lembretes» tem de caber na coluna de uma base criada antes de ela existir. */
final class CapabilitySectionsIncludeRemindersTest extends MysqlDashboardTestCase
{
    public function testAnOlderDatabaseLearnsTheRemindersSection(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("DELETE FROM capabilities WHERE section = 'reminders'");
        $pdo->exec("ALTER TABLE capabilities MODIFY section ENUM('telemetry', 'health', 'contacts', 'alarms', 'settings_system') NOT NULL");

        (new CapabilitySectionsIncludeReminders())->up($pdo);

        $pdo->exec("INSERT INTO capabilities (device_type, section, capability_key, label) VALUES ('watch', 'reminders', 'alarm_clock', 'Despertadores')");
        self::assertSame(
            'reminders',
            $pdo->query("SELECT section FROM capabilities WHERE capability_key = 'alarm_clock' AND device_type = 'watch'")->fetchColumn(),
        );
    }

    public function testRunningItOnACurrentDatabaseChangesNothing(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $before = $pdo->query("SHOW COLUMNS FROM capabilities LIKE 'section'")->fetch(\PDO::FETCH_ASSOC);

        (new CapabilitySectionsIncludeReminders())->up($pdo);

        self::assertSame($before, $pdo->query("SHOW COLUMNS FROM capabilities LIKE 'section'")->fetch(\PDO::FETCH_ASSOC));
    }
}
