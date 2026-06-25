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
        $stmt = $this->pdo->prepare('SELECT feature FROM model_capabilities WHERE model_id = ? AND enabled = 1 ORDER BY feature');
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
        $stmt = $this->pdo->prepare("SELECT model_id, feature FROM model_capabilities WHERE enabled = 1 AND model_id IN ($placeholders) ORDER BY model_id, feature");
        $stmt->execute($modelIds);

        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $modelId = (int)($row['model_id'] ?? 0);
            if ($modelId <= 0) {
                continue;
            }
            $result[$modelId] ??= [];
            $result[$modelId][] = (string)($row['feature'] ?? '');
        }

        return $result;
    }

    /**
     * @param list<string> $features
     */
    public function replaceForModelId(int $modelId, array $features): void
    {
        $features = $this->normalizeFeatures($features);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->pdo->beginTransaction();
        $delete = $this->pdo->prepare('DELETE FROM model_capabilities WHERE model_id = ?');
        $delete->execute([$modelId]);

        if ($features !== []) {
            $insert = $this->pdo->prepare('INSERT INTO model_capabilities (model_id, feature, enabled, created_at, updated_at) VALUES (?, ?, 1, ?, ?)');
            foreach ($features as $feature) {
                $insert->execute([$modelId, $feature, $now, $now]);
            }
        }

        $this->pdo->commit();
    }

    /**
     * @param list<string> $features
     */
    public function ensureFeaturesForModelId(int $modelId, array $features): void
    {
        $features = $this->normalizeFeatures($features);
        if ($features === []) {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($features as $feature) {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND feature = ?');
            $exists->execute([$modelId, $feature]);
            if ((int)$exists->fetchColumn() > 0) {
                continue;
            }

            $insert = $this->pdo->prepare('
                INSERT INTO model_capabilities (model_id, feature, enabled, created_at, updated_at)
                VALUES (?, ?, 1, ?, ?)
            ');
            $insert->execute([$modelId, $feature, $now, $now]);
        }
    }

    /**
     * @param list<string> $features
     * @return list<string>
     */
    private function normalizeFeatures(array $features): array
    {
        $normalized = [];
        foreach ($features as $feature) {
            $value = trim((string)$feature);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }
}
