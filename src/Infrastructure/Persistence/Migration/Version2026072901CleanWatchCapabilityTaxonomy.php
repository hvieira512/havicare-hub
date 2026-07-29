<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026072901CleanWatchCapabilityTaxonomy implements Migration
{
    private const INTERNAL_CAPABILITY_KEYS = [
        'device_binding',
        'device_settings_sync',
    ];

    public function version(): string
    {
        return '2026072901_clean_watch_capability_taxonomy';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->seedReferenceData($pdo);

        $placeholders = implode(',', array_fill(0, count(self::INTERNAL_CAPABILITY_KEYS), '?'));
        $delete = $pdo->prepare(
            "DELETE FROM capabilities
             WHERE device_type = 'watch'
               AND capability_key IN ({$placeholders})"
        );
        $delete->execute(self::INTERNAL_CAPABILITY_KEYS);
    }
}
