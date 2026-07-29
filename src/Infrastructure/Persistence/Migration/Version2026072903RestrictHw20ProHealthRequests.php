<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026072903RestrictHw20ProHealthRequests implements Migration
{
    private const NON_REQUESTABLE_CAPABILITIES = [
        'heart_rate',
        'temperature',
        'breath_rate',
        'ecg',
        'hrv',
        'ppg',
        'rr_interval',
    ];

    public function version(): string
    {
        return '2026072903_restrict_hw20pro_health_requests';
    }

    public function up(PDO $pdo): void
    {
        $schema = new MysqlSchema($pdo);
        if (!$schema->hasColumn('model_capabilities', 'is_requestable')) {
            $pdo->exec('
                ALTER TABLE model_capabilities
                ADD COLUMN is_requestable TINYINT(1) NULL DEFAULT NULL AFTER enabled
            ');
        }

        $placeholders = implode(',', array_fill(0, count(self::NON_REQUESTABLE_CAPABILITIES), '?'));
        $statement = $pdo->prepare("
            UPDATE model_capabilities mc
            JOIN models m ON m.id = mc.model_id
            JOIN suppliers s ON s.id = m.supplier_id
            JOIN capabilities c ON c.id = mc.capability_id
            SET mc.is_requestable = 0
            WHERE s.name = ?
              AND m.internal_model = ?
              AND c.capability_key IN ({$placeholders})
        ");
        $statement->execute([
            'Wonlex',
            'HW20PRO',
            ...self::NON_REQUESTABLE_CAPABILITIES,
        ]);
    }
}
