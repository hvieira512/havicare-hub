<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use Hub\Domain\Capability\CapabilityCatalog;
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
        return $this->enabledFeatures[$modelId] ??= $this->enabledFeaturesForModelIds([$modelId])[$modelId] ?? [];
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
        $enabled = array_flip($this->enabledFeaturesForModelId($modelId));
        $stmt = $this->pdo->prepare('
            SELECT capability_key, is_requestable, device_type
            FROM model_capabilities WHERE model_id = ? AND enabled = 1 ORDER BY capability_key
        ');
        $stmt->execute([$modelId]);

        $requestable = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)$row['capability_key'];
            $definition = self::catalogue((string)$row['device_type'])[$key] ?? null;
            if ($definition === null || !isset($enabled[$key])) {
                continue;
            }
            if ($telemetryOnly && $definition['section'] !== 'telemetry') {
                continue;
            }
            $flag = $row['is_requestable'] === null ? !empty($definition['isRequestable']) : (bool)$row['is_requestable'];
            if ($flag) {
                $requestable[$key] = true;
            }
        }

        return array_keys($requestable);
    }

    /** @return array<string, array<string, mixed>> */
    private static function catalogue(string $deviceType): array
    {
        $byKey = [];
        foreach (CapabilityCatalog::definitionsForDeviceType($deviceType) as $definition) {
            $byKey[(string)$definition['key']] = $definition;
        }

        return $byKey;
    }

    /**
     * @param list<string> $capabilityKeys
     */
    public function replaceTelemetryRequestabilityForModelId(int $modelId, array $capabilityKeys): void
    {
        $capabilityKeys = $this->normalizeCapabilityKeys($modelId, $capabilityKeys);
        $selected = array_fill_keys($capabilityKeys, true);
        $rows = $this->pdo->prepare('
            SELECT device_type, capability_key FROM model_capabilities WHERE model_id = ? AND enabled = 1
        ');
        $rows->execute([$modelId]);

        $update = $this->pdo->prepare('
            UPDATE model_capabilities
            SET is_requestable = ?
            WHERE model_id = ? AND device_type = ? AND capability_key = ?
        ');
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (string)($row['capability_key'] ?? '');
            $definition = self::catalogue((string)$row['device_type'])[$key] ?? null;
            if (($definition['section'] ?? '') !== 'telemetry') {
                continue;
            }
            $catalogRequestable = !empty($definition['isRequestable']);
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
            $result[$modelId][$key] = true;
        }

        return array_map(array_keys(...), $result);
    }

    /**
     * @param list<string> $capabilityKeys
     */
    public function replaceForModelId(int $modelId, array $capabilityKeys): void
    {
        $capabilityKeys = $this->normalizeCapabilityKeys($modelId, $capabilityKeys);
        $deviceType = $this->modelContextForModelId($modelId)['device_type'];

        // Desligar tudo e religar o que ficou são uma só operação. O PDO não aninha
        // transacções, e quem a abriu é que a fecha.
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $disable = $this->pdo->prepare('UPDATE model_capabilities SET enabled = 0 WHERE model_id = ?');
            $disable->execute([$modelId]);

            if ($capabilityKeys !== []) {
                $params = [];
                foreach ($capabilityKeys as $capabilityKey) {
                    array_push($params, $modelId, $deviceType, $capabilityKey);
                }
                $this->pdo->prepare(
                    'INSERT INTO model_capabilities (model_id, device_type, capability_key, enabled) VALUES '
                    . implode(', ', array_fill(0, count($capabilityKeys), '(?, ?, ?, 1)'))
                    . ' ON DUPLICATE KEY UPDATE enabled = 1'
                )->execute($params);
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        if ($ownsTransaction) {
            $this->pdo->commit();
        }
        $this->enabledFeatures = [];
    }

    /**
     * As chaves pedidas que estão no template do modelo; o resto é descartado.
     *
     * @param list<string> $capabilityKeys
     * @return list<string>
     */
    private function normalizeCapabilityKeys(int $modelId, array $capabilityKeys): array
    {
        $context = $this->modelContextForModelId($modelId);
        $allowed = array_flip(array_intersect(
            SupplierCapabilityTemplate::keysForModel($context['supplier_name'], $context['internal_model'], $context['device_type']),
            CapabilityCatalog::keysForDeviceType($context['device_type']),
        ));

        $normalized = [];
        foreach ($capabilityKeys as $key) {
            $key = trim((string)$key);
            if (isset($allowed[$key])) {
                $normalized[$key] = $key;
            }
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
}
