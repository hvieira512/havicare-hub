<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Moves Vivistar devices off the retired "phonebook" configuration key.
 *
 * Vivistar's BP14 contact list was called "phonebook" until it was renamed to
 * "call_whitelist" -- the watch has no phone book, BP14 filters which numbers
 * may call it. The rename shipped without touching the rows already written,
 * so devices configured before it still carry a phonebook the model does not
 * support: it shows on the device page, counts towards configuration sync, and
 * cannot be edited or removed through the API, which now correctly rejects the
 * key as not enabled for the model.
 *
 * Devices that have since been given a call_whitelist keep it -- the newer row
 * is the current intent -- and their stale phonebook row goes. Devices that
 * never got one have theirs renamed, preserving contacts the operator entered.
 *
 * Only vivistar-iw rows are touched: 4P Touch and Wonlex genuinely have a
 * phone book.
 */
final class Version2026081101MigrateVivistarPhonebookRows implements Migration
{
    public function version(): string
    {
        return '2026081101_migrate_vivistar_phonebook_rows';
    }

    public function up(PDO $pdo): void
    {
        // Superseded: a call_whitelist row already carries the current intent.
        $pdo->exec("
            DELETE p FROM device_configurations p
            INNER JOIN device_configurations w
                ON w.imei = p.imei
               AND w.config_key = 'call_whitelist'
               AND w.protocol = 'vivistar-iw'
            WHERE p.protocol = 'vivistar-iw'
              AND p.config_key = 'phonebook'
        ");

        // The rest are the same BP14 list under the old name; carry them over
        // so the contacts already entered are not lost.
        $pdo->exec("
            UPDATE device_configurations
            SET config_key = 'call_whitelist',
                native_key = 'call_whitelist',
                command = 'BP14'
            WHERE protocol = 'vivistar-iw'
              AND config_key = 'phonebook'
        ");
    }
}
