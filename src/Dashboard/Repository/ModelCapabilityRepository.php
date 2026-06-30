<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class ModelCapabilityRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<string>
     */
    public function enabledFeaturesForModelId(int $modelId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT c.capability_key
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE mc.model_id = ? AND mc.enabled = 1
            ORDER BY c.capability_key
        ');
        $stmt->execute([$modelId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /**
     * @param list<int> $modelIds
     * @return array<int, list<string>>
     */
    public function enabledFeaturesForModelIds(array $modelIds): array
    {
        $modelIds = array_values(array_unique(array_map('intval', $modelIds)));
        if ($modelIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT mc.model_id, c.capability_key
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE mc.enabled = 1 AND mc.model_id IN ($placeholders)
            ORDER BY mc.model_id, c.capability_key
        ");
        $stmt->execute($modelIds);

        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $modelId = (int)($row['model_id'] ?? 0);
            if ($modelId <= 0) {
                continue;
            }
            $result[$modelId] ??= [];
            $result[$modelId][] = (string)($row['capability_key'] ?? '');
        }

        return $result;
    }

    /**
     * @param list<int|string> $capabilityIds
     */
    public function replaceForModelId(int $modelId, array $capabilityIds): void
    {
        $capabilityIds = $this->normalizeCapabilityIds($modelId, $capabilityIds);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->pdo->beginTransaction();
        $delete = $this->pdo->prepare('DELETE FROM model_capabilities WHERE model_id = ?');
        $delete->execute([$modelId]);

        if ($capabilityIds !== []) {
            $insert = $this->pdo->prepare('INSERT INTO model_capabilities (model_id, capability_id, enabled, created_at, updated_at) VALUES (?, ?, 1, ?, ?)');
            foreach ($capabilityIds as $capabilityId) {
                $insert->execute([$modelId, $capabilityId, $now, $now]);
            }
        }

        $this->pdo->commit();
    }

    /**
     * @param list<int|string> $capabilityIds
     */
    public function ensureCapabilityIdsForModelId(int $modelId, array $capabilityIds): void
    {
        $capabilityIds = $this->normalizeCapabilityIds($modelId, $capabilityIds);
        if ($capabilityIds === []) {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($capabilityIds as $capabilityId) {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND capability_id = ?');
            $exists->execute([$modelId, $capabilityId]);
            if ((int)$exists->fetchColumn() > 0) {
                continue;
            }

            $insert = $this->pdo->prepare('
                INSERT INTO model_capabilities (model_id, capability_id, enabled, created_at, updated_at)
                VALUES (?, ?, 1, ?, ?)
            ');
            $insert->execute([$modelId, $capabilityId, $now, $now]);
        }
    }

    /**
     * @param list<int|string> $capabilityIds
     * @return list<int>
     */
    private function normalizeCapabilityIds(int $modelId, array $capabilityIds): array
    {
        $deviceType = $this->deviceTypeForModelId($modelId);
        $normalized = [];
        foreach ($capabilityIds as $capabilityId) {
            if (is_string($capabilityId) && !ctype_digit($capabilityId)) {
                $value = $this->capabilityIdForDeviceTypeAndKey($deviceType, trim($capabilityId));
                if ($value === null) {
                    continue;
                }
                $normalized[$value] = $value;
                continue;
            }

            $value = (int)$capabilityId;
            if ($value <= 0) {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private function deviceTypeForModelId(int $modelId): string
    {
        $stmt = $this->pdo->prepare('SELECT device_type FROM models WHERE id = ?');
        $stmt->execute([$modelId]);
        $deviceType = $stmt->fetchColumn();

        return is_string($deviceType) && $deviceType !== '' ? $deviceType : 'watch';
    }

    private function capabilityIdForDeviceTypeAndKey(string $deviceType, string $key): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $stmt->execute([$deviceType, $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int)$value;
    }
}
