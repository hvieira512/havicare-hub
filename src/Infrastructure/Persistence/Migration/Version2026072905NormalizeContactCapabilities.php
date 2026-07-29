<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026072905NormalizeContactCapabilities implements Migration
{
    public function version(): string
    {
        return '2026072905_normalize_contact_capabilities';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->seedReferenceData($pdo);

        $ids = $pdo->query("
            SELECT capability_key, id
            FROM capabilities
            WHERE device_type = 'watch'
              AND capability_key IN (
                  'call_in_restriction',
                  'whitelist_enabled',
                  'call_whitelist',
                  'phonebook'
              )
        ")->fetchAll(PDO::FETCH_KEY_PAIR);
        $oldRestrictionId = (int)($ids['call_in_restriction'] ?? 0);
        $whitelistEnabledId = (int)($ids['whitelist_enabled'] ?? 0);
        $callWhitelistId = (int)($ids['call_whitelist'] ?? 0);
        $phonebookId = (int)($ids['phonebook'] ?? 0);

        if ($oldRestrictionId > 0 && $whitelistEnabledId > 0) {
            $rows = $pdo->query(
                "SELECT model_id, enabled, is_requestable
                 FROM model_capabilities
                 WHERE capability_id = {$oldRestrictionId}"
            )->fetchAll(PDO::FETCH_ASSOC);
            $upsert = $pdo->prepare('
                INSERT INTO model_capabilities (model_id, capability_id, enabled, is_requestable)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    enabled = GREATEST(enabled, VALUES(enabled)),
                    is_requestable = COALESCE(is_requestable, VALUES(is_requestable))
            ');
            foreach ($rows as $row) {
                $upsert->execute([
                    (int)$row['model_id'],
                    $whitelistEnabledId,
                    (int)$row['enabled'],
                    $row['is_requestable'],
                ]);
            }
        }

        if ($callWhitelistId > 0 && $phonebookId > 0) {
            $wonlexModels = $pdo->query("
                SELECT m.id
                FROM models m
                JOIN suppliers s ON s.id = m.supplier_id
                WHERE s.name = 'Wonlex' AND m.device_type = 'watch'
            ")->fetchAll(PDO::FETCH_COLUMN);
            $select = $pdo->prepare('
                SELECT enabled, is_requestable
                FROM model_capabilities
                WHERE model_id = ? AND capability_id = ?
            ');
            $upsert = $pdo->prepare('
                INSERT INTO model_capabilities (model_id, capability_id, enabled, is_requestable)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    enabled = GREATEST(enabled, VALUES(enabled)),
                    is_requestable = COALESCE(is_requestable, VALUES(is_requestable))
            ');
            $delete = $pdo->prepare(
                'DELETE FROM model_capabilities WHERE model_id = ? AND capability_id = ?'
            );
            foreach ($wonlexModels as $modelId) {
                $select->execute([(int)$modelId, $callWhitelistId]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $upsert->execute([
                        (int)$modelId,
                        $phonebookId,
                        (int)$row['enabled'],
                        $row['is_requestable'],
                    ]);
                    $delete->execute([(int)$modelId, $callWhitelistId]);
                }
            }
        }

        $pdo->exec("
            UPDATE device_configurations
            SET config_key = 'phonebook'
            WHERE native_key = 'familyNumber'
        ");
        $pdo->exec("
            UPDATE device_configurations
            SET config_key = 'whitelist_enabled'
            WHERE native_key IN ('wonlexCallInLimitSwitch', 'callInRestriction')
               OR config_key = 'call_in_restriction'
        ");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'watch'
              AND capability_key = 'call_in_restriction'
        ");
    }
}
