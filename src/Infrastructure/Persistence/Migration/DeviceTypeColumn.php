<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Canonical definition of the device_type ENUM.
 *
 * Migrations that pin a literal enum list narrow the column when the catalog
 * grows: a fresh database is created from database/schema.sql with every type,
 * seeded, and then an older migration replays and truncates the rows it does
 * not know about. Both must therefore widen from one shared list, and this list
 * must stay in sync with database/schema.sql.
 */
final class DeviceTypeColumn
{
    /** @var list<string> */
    private const TYPES = ['watch', 'ncs', 'radar', 'gateway', 'diaper_sensor', 'bracelet'];

    private const TABLES = [
        'models' => "NOT NULL DEFAULT 'watch'",
        'supplier_device_types' => 'NOT NULL',
        'capabilities' => "NOT NULL DEFAULT 'watch'",
        'whitelist' => "NOT NULL DEFAULT 'watch'",
    ];

    public static function sql(): string
    {
        return "ENUM('" . implode("', '", self::TYPES) . "')";
    }

    public static function widen(PDO $pdo): void
    {
        $type = self::sql();
        foreach (self::TABLES as $table => $constraints) {
            $pdo->exec("ALTER TABLE {$table} MODIFY device_type {$type} {$constraints}");
        }
    }
}
