<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026072904RemoveUnsupportedWonlexReports implements Migration
{
    private const REMOVED_CAPABILITY_KEYS = [
        'call_log',
        'sms',
        'ecg_analysis',
    ];

    public function version(): string
    {
        return '2026072904_remove_unsupported_wonlex_reports';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->seedReferenceData($pdo);

        $placeholders = implode(',', array_fill(0, count(self::REMOVED_CAPABILITY_KEYS), '?'));
        $delete = $pdo->prepare(
            "DELETE FROM capabilities
             WHERE device_type = 'watch'
               AND capability_key IN ({$placeholders})"
        );
        $delete->execute(self::REMOVED_CAPABILITY_KEYS);
    }
}
