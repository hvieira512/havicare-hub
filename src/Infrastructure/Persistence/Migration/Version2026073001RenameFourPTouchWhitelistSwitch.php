<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026073001RenameFourPTouchWhitelistSwitch implements Migration
{
    public function version(): string
    {
        return '2026073001_rename_four_p_touch_whitelist_switch';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            DELETE legacy
            FROM device_configurations legacy
            JOIN device_configurations documented
              ON documented.imei = legacy.imei
             AND documented.config_key = legacy.config_key
             AND documented.protocol = legacy.protocol
             AND documented.native_key = 'callInRestriction'
            WHERE legacy.protocol = 'four-p-touch'
              AND legacy.native_key = 'whitelistSwitch'
        ");
        $pdo->exec("
            UPDATE device_configurations
            SET native_key = 'rejectUnknownCalls',
                config_key = 'whitelist_enabled',
                command = 'DEVREFUSEPHONESWITCH'
            WHERE protocol = 'four-p-touch'
              AND native_key IN ('whitelistSwitch', 'callInRestriction')
        ");
    }
}
