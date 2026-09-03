<?php

namespace Hub\Api\Repository;

use Hub\Domain\SupplierCapabilityTemplate;
use PDO;

final class ModelCapabilityRepository
{
    /** @var array<int, list<string>> */
    private array $enabledFeatures = [];

    public function __construct(private PDO $pdo)
    {
    }

    // Um só `/api/devices/{imei}` pede isto sete vezes com o mesmo id.
    /**
     * @return list<string>
     */
    public function enabledFeaturesForModelId(int $modelId): array
    {
        return $this->enabledFeatures[$modelId] ??= $this->loadEnabledFeaturesForModelId($modelId);
    }

    /**
     * @return list<string>
     */
    private function loadEnabledFeaturesForModelId(int $modelId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT mc.capability_key, m.device_type, m.internal_model, s.name AS supplier_name
            FROM model_capabilities mc
            JOIN models m ON m.id = mc.model_id
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE mc.model_id = ? AND mc.enabled = 1
            ORDER BY mc.capability_key
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
     * @return list<string>
     */
    public function requestableFeaturesForModelId(int $modelId): array
    {
        return $this->requestableFeaturesForModelIdFiltered($modelId, false);
    }

    /**
     * @return list<string>
     */
    public function requestableTelemetryFeaturesForModelId(int $modelId): array
    {
        return $this->requestableFeaturesForModelIdFiltered($modelId, true);
    }

    /**
     * @return list<string>
     */
    private function requestableFeaturesForModelIdFiltered(int $modelId, bool $telemetryOnly): array
    {
        $telemetryCondition = $telemetryOnly ? "AND c.section = 'telemetry'" : '';
        $stmt = $this->pdo->prepare('
            SELECT mc.capability_key, m.device_type, m.internal_model, s.name AS supplier_name
            FROM model_capabilities mc
            JOIN capabilities c ON c.device_type = mc.device_type AND c.capability_key = mc.capability_key
            JOIN models m ON m.id = mc.model_id
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE mc.model_id = ?
              AND mc.enabled = 1
              AND COALESCE(mc.is_requestable, c.is_requestable) = 1
              ' . $telemetryCondition . '
            ORDER BY mc.capability_key
        ');
        $stmt->execute([$modelId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $allowed = $this->allowedFeaturesForModelRow($rows[0]);
        $requestable = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['capability_key'] ?? ''));
            if ($key === '' || !isset($allowed[$key])) {
                continue;
            }
            $requestable[$key] = true;
        }

        return array_keys($requestable);
    }

    /**
     * @param list<int|string> $capabilityIds
     */
    public function replaceTelemetryRequestabilityForModelId(int $modelId, array $capabilityIds): void
    {
        $capabilityKeys = $this->normalizeCapabilityKeys($modelId, $capabilityIds);
        $selected = array_fill_keys($capabilityKeys, true);
        $rows = $this->pdo->prepare('
            SELECT mc.device_type, mc.capability_key, c.is_requestable AS catalog_requestable
            FROM model_capabilities mc
            JOIN capabilities c ON c.device_type = mc.device_type AND c.capability_key = mc.capability_key
            WHERE mc.model_id = ?
              AND mc.enabled = 1
              AND c.section = \'telemetry\'
        ');
        $rows->execute([$modelId]);

        $update = $this->pdo->prepare('
            UPDATE model_capabilities
            SET is_requestable = ?
            WHERE model_id = ? AND device_type = ? AND capability_key = ?
        ');
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (string)($row['capability_key'] ?? '');
            $catalogRequestable = (bool)($row['catalog_requestable'] ?? false);
            $override = $catalogRequestable ? (isset($selected[$key]) ? 1 : 0) : null;
            $update->execute([$override, $modelId, (string)($row['device_type'] ?? ''), $key]);
        }

        $this->enabledFeatures = [];
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
            SELECT mc.model_id, mc.capability_key, m.device_type, m.internal_model, s.name AS supplier_name
            FROM model_capabilities mc
            JOIN models m ON m.id = mc.model_id
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE mc.enabled = 1 AND mc.model_id IN ($placeholders)
            ORDER BY mc.model_id, mc.capability_key
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
            $cacheKey = trim((string)($row['supplier_name'] ?? ''))
                . ':' . trim((string)($row['internal_model'] ?? ''))
                . ':' . trim((string)($row['device_type'] ?? ''));
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
        $capabilityKeys = $this->normalizeCapabilityKeys($modelId, $capabilityIds);
        $deviceType = $this->modelContextForModelId($modelId)['device_type'];

        $this->pdo->beginTransaction();
        $disable = $this->pdo->prepare('UPDATE model_capabilities SET enabled = 0 WHERE model_id = ?');
        $disable->execute([$modelId]);

        if ($capabilityKeys !== []) {
            $insert = $this->pdo->prepare('
                INSERT INTO model_capabilities (model_id, device_type, capability_key, enabled)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE enabled = 1
            ');
            foreach ($capabilityKeys as $capabilityKey) {
                $insert->execute([$modelId, $deviceType, $capabilityKey]);
            }
        }

        $this->pdo->commit();
        $this->enabledFeatures = [];
    }

    /**
     * @param list<int|string> $capabilityIds
     * As chaves que este modelo pode ter, a partir do que o chamador pediu.
     *
     * O contrato aceita as duas formas -- a chave, ou o `capabilities.id` que a API expõe --,
     * e o que sai é sempre a chave, que é o que a ligação passou a guardar. O que não esteja
     * no template do modelo é descartado, como sempre foi.
     *
     * @param list<int|string> $capabilityIds
     * @return list<string>
     */
    private function normalizeCapabilityKeys(int $modelId, array $capabilityIds): array
    {
        $allowed = $this->allowedCapabilityIdsForModelId($modelId);
        $keyById = array_flip($allowed['keys']);
        $normalized = [];
        foreach ($capabilityIds as $capabilityId) {
            if (is_string($capabilityId) && !ctype_digit($capabilityId)) {
                $key = trim($capabilityId);
                if ($key === '' || !isset($allowed['keys'][$key])) {
                    continue;
                }
                $normalized[$key] = $key;
                continue;
            }

            $value = (int)$capabilityId;
            if ($value <= 0 || !isset($keyById[$value])) {
                continue;
            }
            $normalized[$keyById[$value]] = $keyById[$value];
        }

        return array_values($normalized);
    }

    /**
     * @return array{device_type: string, supplier_name: string, internal_model: string}
     */
    private function modelContextForModelId(int $modelId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT m.device_type, m.internal_model, s.name AS supplier_name
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE m.id = ?
        ');
        $stmt->execute([$modelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'device_type' => is_string($row['device_type'] ?? null) && $row['device_type'] !== '' ? (string)$row['device_type'] : 'watch',
            'supplier_name' => is_string($row['supplier_name'] ?? null) ? (string)$row['supplier_name'] : '',
            'internal_model' => is_string($row['internal_model'] ?? null) ? (string)$row['internal_model'] : '',
        ];
    }

    /**
     * @return array{ids: array<int, int>, keys: array<string, int>}
     */
    private function allowedCapabilityIdsForModelId(int $modelId): array
    {
        $context = $this->modelContextForModelId($modelId);
        $allowedKeys = SupplierCapabilityTemplate::keysForModel(
            $context['supplier_name'],
            $context['internal_model'],
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
        $allowed = SupplierCapabilityTemplate::keysForModel(
            (string)($row['supplier_name'] ?? ''),
            (string)($row['internal_model'] ?? ''),
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
