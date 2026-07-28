<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026072801SyncWonlexAdultHealthCapabilities implements Migration
{
    private const CAPABILITY_KEYS = [
        'call_log',
        'sms',
        'device_state',
        'ecg_analysis',
        'call_whitelist',
        'sos_contacts',
        'reset_device',
        'restart_device',
        'power_off',
        'find_device',
        'weather_data',
    ];

    public function version(): string
    {
        return '2026072801_sync_wonlex_adult_health_capabilities';
    }

    public function up(PDO $pdo): void
    {
        $seeder = new ReferenceCatalogSeeder();
        $seeder->seedReferenceData($pdo);

        $models = $pdo->query("
            SELECT m.id
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Wonlex' AND m.device_type = 'watch'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $capability = $pdo->prepare(
            'SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?'
        );
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled) VALUES (?, ?, 1)'
        );

        foreach ($models as $modelId) {
            foreach (self::CAPABILITY_KEYS as $key) {
                $capability->execute(['watch', $key]);
                $capabilityId = (int)($capability->fetchColumn() ?: 0);
                if ($capabilityId > 0) {
                    $insert->execute([(int)$modelId, $capabilityId]);
                }
            }
        }
    }
}
