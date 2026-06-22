<?php

namespace Hub\Dashboard;

use Hub\Command\DeviceCommandCatalog;
use PDO;

final class DashboardDatabase
{
    private PDO $pdo;

    private const DEFAULT_MODELS = [
        ['Wonlex', 'HW20PRO', 'wonlex-json', ''],
        ['Wonlex', 'L08 Pro', 'wonlex-json', ''],
        ['Vivistar', 'L08 Pro', 'vivistar-iw', ''],
        ['Vivistar', 'VIVISTAR-CARE', 'vivistar-iw', ''],
        ['Vivistar', 'VIVISTAR-LITE', 'vivistar-iw', ''],
        ['4P Touch', '4P-TOUCH', 'four-p-touch', ''],
        ['4P Touch', 'D46', 'four-p-touch', ''],
    ];

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__ . '/../../var/db/dashboard.sqlite';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO("sqlite:{$path}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $this->bootstrapSchema();
        $this->seedDefaults();
        $this->seedDefaultModelRequestCapabilities();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function bootstrapSchema(): void
    {
        $schemaPath = __DIR__ . '/../../database/schema.sql';
        $schema = file_get_contents($schemaPath);
        if (!is_string($schema) || trim($schema) === '') {
            throw new \RuntimeException('database/schema.sql is missing or empty');
        }

        $this->pdo->exec($schema);
        $this->dropLegacyHistoryStorage();

        // Compatibility for databases created before device type and license support.
        $this->ensureColumn('whitelist', 'device_type', 'TEXT NOT NULL DEFAULT "watch"');
        $this->ensureColumn('whitelist', 'license_id', 'TEXT NOT NULL DEFAULT "0"');
        $this->ensureColumn('whitelist', 'sim_number', 'TEXT NOT NULL DEFAULT ""');
        $this->ensureColumn('whitelist', 'device_id', 'TEXT NOT NULL DEFAULT ""');
        $this->ensureColumn('whitelist', 'source_system', 'TEXT NOT NULL DEFAULT ""');
        $this->ensureColumn('whitelist', 'source_device_id', 'TEXT NOT NULL DEFAULT ""');
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->query(sprintf('PRAGMA table_info(%s)', $table));
        $columns = $stmt ? $stmt->fetchAll() : [];
        foreach ($columns as $info) {
            if (($info['name'] ?? null) === $column) {
                return;
            }
        }

        $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private function dropLegacyHistoryStorage(): void
    {
        foreach ([
            'idx_telemetry_imei',
            'idx_telemetry_recorded',
            'idx_events_imei',
            'idx_raw_payloads_imei',
        ] as $index) {
            $this->pdo->exec(sprintf('DROP INDEX IF EXISTS %s', $index));
        }

        foreach (['telemetry', 'events', 'raw_payloads'] as $table) {
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }

    private function seedDefaults(): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $seen = [];
        $supplierStmt = $this->pdo->prepare('INSERT OR IGNORE INTO suppliers (name, enabled, created_at, updated_at) VALUES (?, 1, ?, ?)');
        foreach (self::DEFAULT_MODELS as $row) {
            $name = $row[0];
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $supplierStmt->execute([$name, $now, $now]);
        }

        $nameToId = $this->pdo
            ->query("SELECT name, id FROM suppliers WHERE name IN ('" . implode("','", array_keys($seen)) . "')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $modelStmt = $this->pdo->prepare('INSERT OR IGNORE INTO models (supplier_id, model, protocol, image_path, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (self::DEFAULT_MODELS as $row) {
            if (!isset($nameToId[$row[0]])) {
                throw new \RuntimeException("Default supplier '{$row[0]}' was not created");
            }

            $modelStmt->execute([$nameToId[$row[0]], $row[1], $row[2], $row[3], $now, $now]);
        }
    }

    private function seedDefaultModelRequestCapabilities(): void
    {
        $models = $this->pdo
            ->query('SELECT id, protocol FROM models ORDER BY id')
            ->fetchAll();

        if (!is_array($models) || $models === []) {
            return;
        }

        $insert = $this->pdo->prepare('
            INSERT OR IGNORE INTO model_request_capabilities (model_id, downlink_command, enabled, created_at, updated_at)
            VALUES (?, ?, 1, ?, ?)
        ');
        $now = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($models as $model) {
            $modelId = (int)($model['id'] ?? 0);
            $protocol = (string)($model['protocol'] ?? '');
            if ($modelId <= 0 || $protocol === '') {
                continue;
            }

            foreach ($this->requestCommandsForProtocol($protocol) as $command) {
                $insert->execute([$modelId, $command, $now, $now]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function requestCommandsForProtocol(string $protocol): array
    {
        $commands = [];
        foreach (DeviceCommandCatalog::commandsForProtocol($protocol) as $entry) {
            if ((string)($entry['kind'] ?? '') !== 'request') {
                continue;
            }
            $command = trim((string)($entry['id'] ?? $entry['command'] ?? ''));
            if ($command === '') {
                continue;
            }
            $commands[] = $command;
        }

        return array_values(array_unique($commands));
    }
}
