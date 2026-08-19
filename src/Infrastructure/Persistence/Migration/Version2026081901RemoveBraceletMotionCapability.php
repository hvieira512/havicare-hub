<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Drops the bracelet `motion` capability, because the W6R accelerometer carried
 * no information anyone used.
 *
 * It arrived with every advertisement, so it published roughly every two
 * seconds -- 35k messages a day per bracelet. On a worn bracelet at rest a
 * minute of readings held four distinct magnitudes, 1044/1048/1052/1056 mg:
 * gravity plus the sensor's 4 mg resolution. The publish throttle could not
 * suppress it, because that same noise changed the payload fingerprint on
 * almost every reading.
 *
 * Nothing consumed it. Proximity alarms are decided from RSSI alone, and the
 * one use movement would have served -- distinguishing a worn bracelet from one
 * left on a table near a door -- does not arise: the bracelet is worn, and the
 * alarm exists for a gateway placed on a gate.
 *
 * The capability is removed rather than left declared and empty, so the matrix
 * does not carry a row that can never produce a reading. The opposite
 * inconsistency -- a capability the protocol declares but the database lacks --
 * is what 2026081102_backfill_missing_model_capabilities had to repair, and a
 * declared capability with no data is the same defect facing the other way.
 *
 * Scope resolved before writing this: exactly one row in `model_capabilities`
 * (model 92, MOKO W6R, capability 922, enabled=1) and one in `capabilities`
 * (id 922, bracelet/telemetry/Movimento). The device_type guard keeps any
 * future non-bracelet `motion` untouched.
 */
final class Version2026081901RemoveBraceletMotionCapability implements Migration
{
    public function version(): string
    {
        return '2026081901_remove_bracelet_motion_capability';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            DELETE mc
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE c.device_type = 'bracelet' AND c.capability_key = 'motion'
        ");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'bracelet' AND capability_key = 'motion'
        ");
    }
}
