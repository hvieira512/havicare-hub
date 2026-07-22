<?php

namespace Hub\Api\Repository;

use Hub\Domain\GenericModelCapabilityCatalog;
use PDO;

final class DeviceConfigurationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function allForImei(string $imei): array
    {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM device_configurations
            WHERE imei = ?
            ORDER BY desired_updated_at ASC, reported_at ASC, config_key ASC
        ');
        $stmt->execute([$imei]);

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll());
    }

    public function saveDesired(
        string $imei,
        string $key,
        string $protocol,
        string $supplier,
        string $model,
        string $command,
        array $payload,
        string $status = '',
        string $commandId = ''
    ): void {
        $key = $this->normalizeConfigKey($key);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        if ($this->exists($imei, $key)) {
            $stmt = $this->pdo->prepare('
                UPDATE device_configurations
                SET protocol = ?, supplier = ?, model = ?, command = ?, desired_payload = ?, last_status = ?, last_command_id = ?, desired_updated_at = ?, applied_at = ?
                WHERE imei = ? AND config_key = ?
            ');
            $stmt->execute([$protocol, $supplier, $model, $command, $encoded, $status, $commandId, $now, $now, $imei, $key]);
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, protocol, supplier, model, command, desired_payload, reported_payload,
                last_status, last_command_id, desired_updated_at, applied_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$imei, $key, $protocol, $supplier, $model, $command, $encoded, '{}', $status, $commandId, $now, $now]);
    }

    public function markApplyStatus(string $imei, string $key, string $status, string $commandId = ''): void
    {
        $key = $this->normalizeConfigKey($key);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE device_configurations SET last_status = ?, last_command_id = ?, applied_at = ? WHERE imei = ? AND config_key = ?');
        $stmt->execute([$status, $commandId, $now, $imei, $key]);
    }

    public function saveReported(
        string $imei,
        string $key,
        string $protocol,
        string $supplier,
        string $model,
        string $command,
        array $payload
    ): void {
        $key = $this->normalizeConfigKey($key);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        if ($this->exists($imei, $key)) {
            $stmt = $this->pdo->prepare('
                UPDATE device_configurations
                SET protocol = ?, supplier = ?, model = ?, command = ?, reported_payload = ?, reported_at = ?
                WHERE imei = ? AND config_key = ?
            ');
            $stmt->execute([$protocol, $supplier, $model, $command, $encoded, $now, $imei, $key]);
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, protocol, supplier, model, command, desired_payload, reported_payload, reported_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$imei, $key, $protocol, $supplier, $model, $command, '{}', $encoded, $now]);
    }

    private function exists(string $imei, string $key): bool
    {
        $key = $this->normalizeConfigKey($key);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM device_configurations WHERE imei = ? AND config_key = ?');
        $stmt->execute([$imei, $key]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function normalizeConfigKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }

        return GenericModelCapabilityCatalog::normalizeStoredCapabilityKey($key) ?? $key;
    }

    private function normalizeRow(array $row): array
    {
        $row['desired_payload'] = json_decode((string)($row['desired_payload'] ?? '{}'), true) ?: [];
        $row['reported_payload'] = json_decode((string)($row['reported_payload'] ?? '{}'), true) ?: [];

        return $row;
    }
}
