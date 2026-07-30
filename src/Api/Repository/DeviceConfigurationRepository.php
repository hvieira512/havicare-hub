<?php

namespace Hub\Api\Repository;

use Hub\Domain\Capability\CapabilityCatalog;
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
            ORDER BY desired_updated_at ASC, reported_at ASC, config_key ASC, native_key ASC
        ');
        $stmt->execute([$imei]);

        $latestByLogicalNativeKey = [];
        foreach (array_map([$this, 'normalizeRow'], $stmt->fetchAll()) as $row) {
            $nativeKey = trim((string)($row['native_key'] ?? $row['config_key'] ?? ''));
            $genericKey = CapabilityCatalog::normalizeStoredCapabilityKey(
                (string)($row['config_key'] ?? $nativeKey)
            ) ?? trim((string)($row['config_key'] ?? $nativeKey));
            $identity = implode("\0", [
                trim((string)($row['protocol'] ?? '')),
                $genericKey,
                $nativeKey,
            ]);
            $existing = $latestByLogicalNativeKey[$identity] ?? null;
            if ($existing === null || $this->isNewerRow($row, $existing)) {
                $latestByLogicalNativeKey[$identity] = $row;
            }
        }

        $rows = array_values($latestByLogicalNativeKey);
        usort($rows, static function (array $left, array $right): int {
            foreach (['desired_updated_at', 'reported_at', 'config_key', 'native_key'] as $key) {
                $comparison = strcmp((string)($left[$key] ?? ''), (string)($right[$key] ?? ''));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return $rows;
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
        $nativeKey = $this->normalizeNativeKey(trim($protocol), trim($key));
        $key = $this->normalizeConfigKey($nativeKey);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        if ($this->exists($imei, $key, $nativeKey)) {
            $stmt = $this->pdo->prepare('
                UPDATE device_configurations
                SET protocol = ?, supplier = ?, model = ?, command = ?, desired_payload = ?, last_status = ?, last_command_id = ?, desired_updated_at = ?
                WHERE imei = ? AND config_key = ? AND native_key = ?
            ');
            $stmt->execute([$protocol, $supplier, $model, $command, $encoded, $status, $commandId, $now, $imei, $key, $nativeKey]);
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, supplier, model, command, desired_payload, reported_payload,
                last_status, last_command_id, desired_updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$imei, $key, $nativeKey, $protocol, $supplier, $model, $command, $encoded, '{}', $status, $commandId, $now]);
    }

    public function markApplyStatus(string $imei, string $key, string $status, string $commandId = ''): void
    {
        $nativeKey = trim($key);
        $key = $this->normalizeConfigKey($nativeKey);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare("
            UPDATE device_configurations
            SET last_status = ?, last_command_id = ?,
                applied_at = IF(? = 'acked', ?, applied_at)
            WHERE imei = ? AND config_key = ? AND native_key = ?
        ");
        $stmt->execute([$status, $commandId, $status, $now, $imei, $key, $nativeKey]);
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
        $nativeKey = $this->normalizeNativeKey(trim($protocol), trim($key));
        $key = $this->normalizeConfigKey($nativeKey);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        if ($this->exists($imei, $key, $nativeKey)) {
            $stmt = $this->pdo->prepare('
                UPDATE device_configurations
                SET protocol = ?, supplier = ?, model = ?, command = ?, reported_payload = ?, reported_at = ?
                WHERE imei = ? AND config_key = ? AND native_key = ?
            ');
            $stmt->execute([$protocol, $supplier, $model, $command, $encoded, $now, $imei, $key, $nativeKey]);
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, supplier, model, command, desired_payload, reported_payload, reported_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$imei, $key, $nativeKey, $protocol, $supplier, $model, $command, '{}', $encoded, $now]);
    }

    private function exists(string $imei, string $key, string $nativeKey): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM device_configurations
            WHERE imei = ? AND config_key = ? AND native_key = ?
        ');
        $stmt->execute([$imei, $key, $nativeKey]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function normalizeConfigKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }

        return CapabilityCatalog::normalizeStoredCapabilityKey($key) ?? $key;
    }

    private function normalizeNativeKey(string $protocol, string $key): string
    {
        if ($key !== 'alarm_clock') {
            return $key;
        }

        return match ($protocol) {
            'vivistar-iw' => 'reminders',
            'four-p-touch' => 'alarmClock',
            default => $key,
        };
    }

    private function normalizeRow(array $row): array
    {
        $row['desired_payload'] = json_decode((string)($row['desired_payload'] ?? '{}'), true) ?: [];
        $row['reported_payload'] = json_decode((string)($row['reported_payload'] ?? '{}'), true) ?: [];

        return $row;
    }

    private function isNewerRow(array $candidate, array $existing): bool
    {
        $candidateTimestamp = $this->rowTimestamp($candidate);
        $existingTimestamp = $this->rowTimestamp($existing);
        if ($candidateTimestamp !== $existingTimestamp) {
            return strcmp($candidateTimestamp, $existingTimestamp) > 0;
        }

        $nativeKey = trim((string)($candidate['native_key'] ?? $candidate['config_key'] ?? ''));
        $canonicalKey = $this->normalizeConfigKey($nativeKey);
        $candidateIsCanonical = (string)($candidate['config_key'] ?? '') === $canonicalKey;
        $existingIsCanonical = (string)($existing['config_key'] ?? '') === $canonicalKey;

        return $candidateIsCanonical && !$existingIsCanonical;
    }

    private function rowTimestamp(array $row): string
    {
        return max(
            (string)($row['desired_updated_at'] ?? ''),
            (string)($row['reported_at'] ?? ''),
            (string)($row['applied_at'] ?? '')
        );
    }
}
