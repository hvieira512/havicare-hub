<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Domain\Capability\CapabilityCatalog;
use PDO;

final class Version2026072405NormalizeConfigurationKeys implements Migration
{
    public function version(): string
    {
        return '2026072405_normalize_configuration_keys';
    }

    public function up(PDO $pdo): void
    {
        if ($this->primaryKeyColumns($pdo) === ['imei', 'config_key', 'native_key']) {
            return;
        }

        $rows = $pdo->query('SELECT * FROM device_configurations')->fetchAll(PDO::FETCH_ASSOC);
        $normalizedRows = [];
        foreach ($rows as $row) {
            $storedKey = trim((string)($row['native_key'] ?? $row['config_key'] ?? ''));
            $genericKey = CapabilityCatalog::normalizeStoredCapabilityKey($storedKey)
                ?? trim((string)($row['config_key'] ?? ''));
            $nativeKey = $this->normalizeNativeKey(
                (string)($row['protocol'] ?? ''),
                $storedKey,
                $genericKey
            );
            if ($genericKey === '' || $nativeKey === '') {
                continue;
            }

            $row['config_key'] = $genericKey;
            $row['native_key'] = $nativeKey;
            $identity = implode("\0", [(string)$row['imei'], $genericKey, $nativeKey]);
            if (!isset($normalizedRows[$identity]) || $this->isNewer($row, $normalizedRows[$identity])) {
                $normalizedRows[$identity] = $row;
            }
        }

        $pdo->exec('DROP TABLE IF EXISTS device_configurations_migrated');
        $pdo->exec($this->createTableSql('device_configurations_migrated'));

        $insert = $pdo->prepare('
            INSERT INTO device_configurations_migrated (
                imei, config_key, native_key, protocol, supplier, model, command,
                desired_payload, reported_payload, last_status, last_command_id,
                desired_updated_at, reported_at, applied_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($normalizedRows as $row) {
            $insert->execute([
                (string)($row['imei'] ?? ''),
                (string)$row['config_key'],
                (string)$row['native_key'],
                (string)($row['protocol'] ?? ''),
                (string)($row['supplier'] ?? ''),
                (string)($row['model'] ?? ''),
                (string)($row['command'] ?? ''),
                (string)($row['desired_payload'] ?? '{}'),
                (string)($row['reported_payload'] ?? '{}'),
                (string)($row['last_status'] ?? ''),
                (string)($row['last_command_id'] ?? ''),
                (string)($row['desired_updated_at'] ?? ''),
                (string)($row['reported_at'] ?? ''),
                (string)($row['applied_at'] ?? ''),
            ]);
        }

        $pdo->exec('
            RENAME TABLE
                device_configurations TO device_configurations_legacy,
                device_configurations_migrated TO device_configurations
        ');
        $pdo->exec('DROP TABLE device_configurations_legacy');
    }

    private function normalizeNativeKey(string $protocol, string $storedKey, string $genericKey): string
    {
        if ($genericKey !== 'alarm_clock' || $storedKey !== 'alarm_clock') {
            return $storedKey;
        }

        return match (trim($protocol)) {
            'vivistar-iw' => 'reminders',
            'four-p-touch' => 'alarmClock',
            default => $storedKey,
        };
    }

    private function isNewer(array $candidate, array $existing): bool
    {
        return $this->rowTimestamp($candidate) >= $this->rowTimestamp($existing);
    }

    private function rowTimestamp(array $row): string
    {
        return max(
            (string)($row['desired_updated_at'] ?? ''),
            (string)($row['reported_at'] ?? ''),
            (string)($row['applied_at'] ?? '')
        );
    }

    private function createTableSql(string $table): string
    {
        return sprintf(<<<'SQL'
            CREATE TABLE `%s` (
                imei VARCHAR(64) NOT NULL,
                config_key VARCHAR(191) NOT NULL,
                native_key VARCHAR(191) NOT NULL,
                protocol VARCHAR(64) NOT NULL,
                supplier VARCHAR(191) NOT NULL DEFAULT '',
                model VARCHAR(191) NOT NULL DEFAULT '',
                command VARCHAR(191) NOT NULL DEFAULT '',
                desired_payload LONGTEXT NOT NULL,
                reported_payload LONGTEXT NOT NULL,
                last_status ENUM('', 'queued', 'waiting', 'acked', 'failed', 'dropped', 'sent') NOT NULL DEFAULT '',
                last_command_id VARCHAR(64) NOT NULL DEFAULT '',
                desired_updated_at VARCHAR(32) NOT NULL DEFAULT '',
                reported_at VARCHAR(32) NOT NULL DEFAULT '',
                applied_at VARCHAR(32) NOT NULL DEFAULT '',
                PRIMARY KEY (imei, config_key, native_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL, $table);
    }

    /**
     * @return list<string>
     */
    private function primaryKeyColumns(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'device_configurations'
              AND CONSTRAINT_NAME = 'PRIMARY'
            ORDER BY ORDINAL_POSITION
        ");
        return array_map('strval', $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []);
    }
}
