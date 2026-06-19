<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class ModelRequestCapabilityRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<string>
     */
    public function enabledCommandsForModelId(int $modelId): array
    {
        $stmt = $this->pdo->prepare('SELECT downlink_command FROM model_request_capabilities WHERE model_id = ? AND enabled = 1 ORDER BY downlink_command');
        $stmt->execute([$modelId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /**
     * @param list<int> $modelIds
     * @return array<int, list<string>>
     */
    public function enabledCommandsForModelIds(array $modelIds): array
    {
        $modelIds = array_values(array_unique(array_map('intval', $modelIds)));
        if ($modelIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
        $stmt = $this->pdo->prepare("SELECT model_id, downlink_command FROM model_request_capabilities WHERE enabled = 1 AND model_id IN ($placeholders) ORDER BY model_id, downlink_command");
        $stmt->execute($modelIds);

        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $modelId = (int)($row['model_id'] ?? 0);
            if ($modelId <= 0) {
                continue;
            }
            $result[$modelId] ??= [];
            $result[$modelId][] = (string)($row['downlink_command'] ?? '');
        }

        return $result;
    }

    /**
     * @param list<string> $commands
     */
    public function replaceForModelId(int $modelId, array $commands): void
    {
        $commands = $this->normalizeCommands($commands);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->pdo->beginTransaction();
        $delete = $this->pdo->prepare('DELETE FROM model_request_capabilities WHERE model_id = ?');
        $delete->execute([$modelId]);

        if ($commands !== []) {
            $insert = $this->pdo->prepare('INSERT INTO model_request_capabilities (model_id, downlink_command, enabled, created_at, updated_at) VALUES (?, ?, 1, ?, ?)');
            foreach ($commands as $command) {
                $insert->execute([$modelId, $command, $now, $now]);
            }
        }

        $this->pdo->commit();
    }

    /**
     * @param list<string> $commands
     */
    public function ensureCommandsForModelId(int $modelId, array $commands): void
    {
        $commands = $this->normalizeCommands($commands);
        if ($commands === []) {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $insert = $this->pdo->prepare('
            INSERT OR IGNORE INTO model_request_capabilities (model_id, downlink_command, enabled, created_at, updated_at)
            VALUES (?, ?, 1, ?, ?)
        ');

        foreach ($commands as $command) {
            $insert->execute([$modelId, $command, $now, $now]);
        }
    }

    /**
     * @param list<string> $commands
     * @return list<string>
     */
    private function normalizeCommands(array $commands): array
    {
        $normalized = [];
        foreach ($commands as $command) {
            $value = trim((string)$command);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }
}
