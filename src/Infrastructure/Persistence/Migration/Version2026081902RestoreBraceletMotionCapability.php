<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Puts back the bracelet `motion` capability that
 * 2026081901_remove_bracelet_motion_capability deleted.
 *
 * The removal was argued from consumption -- nothing read the accelerometer, so
 * it looked like 35k messages a day of pure noise. That reasoning was wrong at
 * the wrong level. `accelerationMg` is what the W6R reports, and translating a
 * field the device sends is normalization, not a derived opinion the hub is
 * free to withhold. Whether anything consumes it today does not change what the
 * device said, and fall detection -- the obvious consumer -- reads exactly this
 * field.
 *
 * A separate migration rather than an edit to 2026081901: that one has already
 * run in production, so its row in `schema_migrations` means it will never run
 * again and changing its body would silently do nothing.
 *
 * Same two steps as 2026081401_diaper_moisture_level_capability, for the same
 * reason. syncCapabilities recreates the row in `capabilities` from the catalog,
 * which is what makes the card exist at all; seedModelCapabilities skips any
 * model that already has rows, so the W6R would never regain the link without
 * the explicit insert.
 *
 * INSERT IGNORE, and no UPDATE: if a row somehow survived with enabled = 0
 * because someone switched it off by hand, it stays off. This repairs a
 * deletion, it does not override a choice.
 *
 * The publish volume that motivated the removal is real and unaddressed here.
 * It belongs to the throttle -- the accelerometer's 4 mg of jitter changes the
 * payload fingerprint on nearly every reading, so fingerprint-based throttling
 * cannot suppress it -- and that is a throttling problem, not a reason to stop
 * reporting what the bracelet measures.
 */
final class Version2026081902RestoreBraceletMotionCapability implements Migration
{
    public function version(): string
    {
        return '2026081902_restore_bracelet_motion_capability';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->syncCapabilities($pdo);

        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled)
            SELECT m.id, c.id, 1
            FROM models m
            JOIN capabilities c
              ON c.device_type = m.device_type
             AND c.capability_key = 'motion'
            WHERE m.device_type = 'bracelet'
        ");
    }
}
