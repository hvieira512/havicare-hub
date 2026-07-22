<?php

namespace Hub\Api\Repository;

use Hub\Domain\SupplierCapabilityTemplate;
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
            SELECT c.capability_key, m.device_type, s.name AS supplier_name
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            JOIN models m ON m.id = mc.model_id
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE mc.model_id = ? AND mc.enabled = 1
            ORDER BY c.capability_key
        ');
        $stmt->execute([$modelId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $allowed = $this->allowedFeaturesForModelRow($rows[0]);
        $enabled = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['capability_key'] ?? ''));
            if ($key === '' || !isset($allowed[$key])) {
                continue;
            }
            $enabled[$key] = true;
        }

        return array_keys($enabled);
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
            SELECT mc.model_id, c.capability_key, m.device_type, s.name AS supplier_name
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            JOIN models m ON m.id = mc.model_id
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE mc.enabled = 1 AND mc.model_id IN ($placeholders)
            ORDER BY mc.model_id, c.capability_key
        ");
        $stmt->execute($modelIds);

        $rows = $stmt->fetchAll();
        $result = [];
        $allowedCache = [];
        foreach ($rows as $row) {
            $modelId = (int)($row['model_id'] ?? 0);
            if ($modelId <= 0) {
                continue;
            }
            $cacheKey = trim((string)($row['supplier_name'] ?? '')) . ':' . trim((string)($row['device_type'] ?? ''));
            if (!array_key_exists($cacheKey, $allowedCache)) {
                $allowedCache[$cacheKey] = $this->allowedFeaturesForModelRow($row);
            }
            $key = trim((string)($row['capability_key'] ?? ''));
            if ($key === '' || !isset($allowedCache[$cacheKey][$key])) {
                continue;
            }
            $result[$modelId] ??= [];
            $result[$modelId][] = $key;
        }

        return $result;
    }

    /**
     * @param list<int|string> $capabilityIds
     */
    public function replaceForModelId(int $modelId, array $capabilityIds): void
    {
        $capabilityIds = $this->normalizeCapabilityIds($modelId, $capabilityIds);

        $this->pdo->beginTransaction();
        $delete = $this->pdo->prepare('DELETE FROM model_capabilities WHERE model_id = ?');
        $delete->execute([$modelId]);

        if ($capabilityIds !== []) {
            $insert = $this->pdo->prepare('INSERT INTO model_capabilities (model_id, capability_id, enabled) VALUES (?, ?, 1)');
            foreach ($capabilityIds as $capabilityId) {
                $insert->execute([$modelId, $capabilityId]);
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

        foreach ($capabilityIds as $capabilityId) {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND capability_id = ?');
            $exists->execute([$modelId, $capabilityId]);
            if ((int)$exists->fetchColumn() > 0) {
                continue;
            }

            $insert = $this->pdo->prepare('
                INSERT INTO model_capabilities (model_id, capability_id, enabled)
                VALUES (?, ?, 1)
            ');
            $insert->execute([$modelId, $capabilityId]);
        }
    }

    /**
     * @param list<int|string> $capabilityIds
     * @return list<int>
     */
    private function normalizeCapabilityIds(int $modelId, array $capabilityIds): array
    {
        $allowed = $this->allowedCapabilityIdsForModelId($modelId);
        $normalized = [];
        foreach ($capabilityIds as $capabilityId) {
            if (is_string($capabilityId) && !ctype_digit($capabilityId)) {
                $key = trim($capabilityId);
                if ($key === '' || !isset($allowed['keys'][$key])) {
                    continue;
                }
                $value = $allowed['keys'][$key];
                $normalized[$value] = $value;
                continue;
            }

            $value = (int)$capabilityId;
            if ($value <= 0 || !isset($allowed['ids'][$value])) {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @return array{device_type: string, supplier_name: string}
     */
    private function modelContextForModelId(int $modelId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT m.device_type, s.name AS supplier_name
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE m.id = ?
        ');
        $stmt->execute([$modelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'device_type' => is_string($row['device_type'] ?? null) && $row['device_type'] !== '' ? (string)$row['device_type'] : 'watch',
            'supplier_name' => is_string($row['supplier_name'] ?? null) ? (string)$row['supplier_name'] : '',
        ];
    }

    /**
     * @return array{ids: array<int, int>, keys: array<string, int>}
     */
    private function allowedCapabilityIdsForModelId(int $modelId): array
    {
        $context = $this->modelContextForModelId($modelId);
        $allowedKeys = SupplierCapabilityTemplate::keysForSupplierDeviceType(
            $context['supplier_name'],
            $context['device_type']
        );

        $ids = [];
        $keys = [];
        foreach ($allowedKeys as $key) {
            $capabilityId = $this->capabilityIdForDeviceTypeAndKey($context['device_type'], $key);
            if ($capabilityId === null) {
                continue;
            }
            $ids[$capabilityId] = $capabilityId;
            $keys[$key] = $capabilityId;
        }

        return ['ids' => $ids, 'keys' => $keys];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, true>
     */
    private function allowedFeaturesForModelRow(array $row): array
    {
        $allowed = SupplierCapabilityTemplate::keysForSupplierDeviceType(
            (string)($row['supplier_name'] ?? ''),
            (string)($row['device_type'] ?? 'watch')
        );

        return array_fill_keys($allowed, true);
    }

    private function capabilityIdForDeviceTypeAndKey(string $deviceType, string $key): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $stmt->execute([$deviceType, $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int)$value;
    }
}
