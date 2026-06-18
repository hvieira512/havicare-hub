<?php

namespace App\Repositories;

use PDO;

final class DashboardDeviceConfigurationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function allForImei(string $imei): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM device_configurations WHERE imei = ? ORDER BY config_key');
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
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $stmt = $this->pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, protocol, supplier, model, command, desired_payload,
                last_status, last_command_id, desired_updated_at, applied_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(imei, config_key) DO UPDATE SET
                protocol = excluded.protocol,
                supplier = excluded.supplier,
                model = excluded.model,
                command = excluded.command,
                desired_payload = excluded.desired_payload,
                last_status = excluded.last_status,
                last_command_id = excluded.last_command_id,
                desired_updated_at = excluded.desired_updated_at,
                applied_at = excluded.applied_at
        ');
        $stmt->execute([$imei, $key, $protocol, $supplier, $model, $command, $encoded, $status, $commandId, $now, $now]);
    }

    public function markApplyStatus(string $imei, string $key, string $status, string $commandId = ''): void
    {
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
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $stmt = $this->pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, protocol, supplier, model, command, reported_payload, reported_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(imei, config_key) DO UPDATE SET
                protocol = excluded.protocol,
                supplier = excluded.supplier,
                model = excluded.model,
                command = excluded.command,
                reported_payload = excluded.reported_payload,
                reported_at = excluded.reported_at
        ');
        $stmt->execute([$imei, $key, $protocol, $supplier, $model, $command, $encoded, $now]);
    }

    private function normalizeRow(array $row): array
    {
        $row['desired_payload'] = json_decode((string)($row['desired_payload'] ?? '{}'), true) ?: [];
        $row['reported_payload'] = json_decode((string)($row['reported_payload'] ?? '{}'), true) ?: [];

        return $row;
    }
}
