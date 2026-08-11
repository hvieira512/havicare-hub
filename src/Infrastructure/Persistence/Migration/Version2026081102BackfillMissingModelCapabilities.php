<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Domain\SupplierCapabilityTemplate;
use PDO;

/**
 * Gives models the capabilities their protocol has gained since they were seeded.
 *
 * seedModelCapabilities skips any model that already has rows, so a capability
 * added to a protocol later never reaches models seeded before it. The effect
 * is a device that plainly supports something -- a Vivistar VL17 has BP77 fall
 * sensitivity -- while the API refuses to configure it, because the matrix says
 * the model does not have the capability.
 *
 * Only missing rows are inserted. A capability switched off by hand keeps its
 * row and stays off: this fills gaps, it does not re-enable anything.
 */
final class Version2026081102BackfillMissingModelCapabilities implements Migration
{
    public function version(): string
    {
        return '2026081102_backfill_missing_model_capabilities';
    }

    public function up(PDO $pdo): void
    {
        $models = $pdo->query('
            SELECT m.id, m.internal_model, m.device_type, s.name AS supplier
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
        ')->fetchAll(PDO::FETCH_ASSOC);

        $existing = $pdo->prepare('
            SELECT c.capability_key
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE mc.model_id = ?
        ');
        $capabilityId = $pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $insert = $pdo->prepare('
            INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled) VALUES (?, ?, 1)
        ');

        foreach ($models as $model) {
            $modelId = (int)$model['id'];
            $deviceType = (string)$model['device_type'];

            $existing->execute([$modelId]);
            $have = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

            $expected = SupplierCapabilityTemplate::keysForModel(
                (string)$model['supplier'],
                (string)$model['internal_model'],
                $deviceType
            );

            foreach ($expected as $key) {
                if (isset($have[$key])) {
                    continue;
                }

                $capabilityId->execute([$deviceType, $key]);
                $id = (int)($capabilityId->fetchColumn() ?: 0);
                if ($id > 0) {
                    $insert->execute([$modelId, $id]);
                }
            }
        }
    }
}
