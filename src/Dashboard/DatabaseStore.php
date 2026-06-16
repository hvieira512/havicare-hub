<?php

namespace Hub\Dashboard;

use PDO;

final class DatabaseStore
{
    private PDO $pdo;

    private const DEFAULT_MODELS = [
        ['Wonlex', 'HW20PRO', 'wonlex-json', ''],
        ['Wonlex', 'L08 Pro', 'wonlex-json', ''],
        ['Vivistar', 'VIVISTAR-CARE', 'vivistar-iw', ''],
        ['Vivistar', 'VIVISTAR-LITE', 'vivistar-iw', ''],
        ['4P Touch', '4P-TOUCH', 'four-p-touch', ''],
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
        $this->initSchema();
        $this->seedDefaults();
    }

    private function initSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS suppliers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS models (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                supplier_id INTEGER NOT NULL REFERENCES suppliers(id),
                model TEXT NOT NULL,
                protocol TEXT NOT NULL,
                image_path TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(supplier_id, model)
            )
        ');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS whitelist (
                imei TEXT PRIMARY KEY,
                supplier TEXT NOT NULL,
                model TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS telemetry (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                imei TEXT NOT NULL,
                type TEXT NOT NULL,
                payload TEXT NOT NULL,
                recorded_at TEXT NOT NULL
            )
        ');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                imei TEXT NOT NULL,
                type TEXT NOT NULL,
                payload TEXT NOT NULL,
                recorded_at TEXT NOT NULL
            )
        ');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS raw_payloads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                imei TEXT NOT NULL,
                payload TEXT NOT NULL,
                recorded_at TEXT NOT NULL
            )
        ');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS device_configurations (
                imei TEXT NOT NULL,
                config_key TEXT NOT NULL,
                protocol TEXT NOT NULL,
                supplier TEXT NOT NULL DEFAULT "",
                model TEXT NOT NULL DEFAULT "",
                command TEXT NOT NULL DEFAULT "",
                desired_payload TEXT NOT NULL DEFAULT "{}",
                reported_payload TEXT NOT NULL DEFAULT "{}",
                last_status TEXT NOT NULL DEFAULT "",
                last_command_id TEXT NOT NULL DEFAULT "",
                desired_updated_at TEXT NOT NULL DEFAULT "",
                reported_at TEXT NOT NULL DEFAULT "",
                applied_at TEXT NOT NULL DEFAULT "",
                PRIMARY KEY (imei, config_key)
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_telemetry_imei ON telemetry(imei)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_telemetry_recorded ON telemetry(recorded_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_imei ON events(imei)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_raw_payloads_imei ON raw_payloads(imei)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_device_configurations_imei ON device_configurations(imei)');
    }

    private function seedDefaults(): void
    {
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM models')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $seen = [];
        $supplierStmt = $this->pdo->prepare('INSERT OR IGNORE INTO suppliers (name, enabled, created_at, updated_at) VALUES (?, 1, ?, ?)');
        foreach (self::DEFAULT_MODELS as $row) {
            $name = $row[0];
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $supplierStmt->execute([$name, $now, $now]);
            }
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

    public function models(): array
    {
        return $this->pdo
            ->query('SELECT m.id, m.supplier_id, s.name AS supplier, m.model, m.protocol, m.image_path AS "image" FROM models m JOIN suppliers s ON s.id = m.supplier_id ORDER BY s.name, m.model')
            ->fetchAll();
    }

    public function supplierList(): array
    {
        return $this->pdo
            ->query("SELECT id, name, enabled, created_at, updated_at, (SELECT COUNT(*) FROM models WHERE supplier_id = suppliers.id) AS model_count FROM suppliers ORDER BY name")
            ->fetchAll();
    }

    public function supplierFind(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE name = ?');
        $stmt->execute([$name]);
        return $stmt->fetch() ?: null;
    }

    public function supplierFindById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function supplierCreate(string $name, bool $enabled = true): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO suppliers (name, enabled, created_at, updated_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $enabled ? 1 : 0, $now, $now]);
        $stmt = $this->pdo->prepare('SELECT id FROM suppliers WHERE name = ?');
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn();
    }

    public function supplierUpdate(int $id, bool $enabled): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE suppliers SET enabled = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, $now, $id]);
    }

    public function supplierRename(int $id, string $newName): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $oldName = $this->findNameById($id);
        if ($oldName === null) {
            return;
        }
        $this->pdo->beginTransaction();
        $this->pdo->prepare('UPDATE suppliers SET name = ?, updated_at = ? WHERE id = ?')->execute([$newName, $now, $id]);
        $this->pdo->prepare('UPDATE whitelist SET supplier = ?, updated_at = ? WHERE supplier = ?')->execute([$newName, $now, $oldName]);
        $this->pdo->commit();
    }

    public function supplierDelete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function supplierCountModels(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM models WHERE supplier_id = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    private function findNameById(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    public function findModel(string $supplier, string $model): ?array
    {
        $stmt = $this->pdo->prepare('SELECT m.*, s.name AS supplier_name FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = ? AND m.model = ?');
        $stmt->execute([$supplier, $model]);
        return $stmt->fetch() ?: null;
    }

    public function findModelById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT m.*, s.name AS supplier_name FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE m.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function protocolForModel(string $supplier, string $model): string
    {
        $m = $this->findModel($supplier, $model);
        return $m['protocol'] ?? '';
    }

    public function addModel(int $supplierId, string $model, string $protocol, ?string $imagePath = null): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $existing = $this->findModelBySupplierId($supplierId, $model);
        $storedImagePath = $imagePath ?? (string)($existing['image_path'] ?? '');
        $stmt = $this->pdo->prepare('
            INSERT INTO models (supplier_id, model, protocol, image_path, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(supplier_id, model) DO UPDATE SET
                protocol = excluded.protocol,
                image_path = excluded.image_path,
                updated_at = ?
        ');
        $stmt->execute([$supplierId, $model, $protocol, $storedImagePath, $now, $now, $now]);
    }

    public function updateModel(int $id, int $supplierId, string $model, string $protocol, ?string $imagePath = null): bool
    {
        $existing = $this->findModelById($id);
        if ($existing === null) {
            return false;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $storedImagePath = $imagePath ?? (string)($existing['image_path'] ?? '');
        $stmt = $this->pdo->prepare('
            UPDATE models
            SET supplier_id = ?, model = ?, protocol = ?, image_path = ?, updated_at = ?
            WHERE id = ?
        ');
        $stmt->execute([$supplierId, $model, $protocol, $storedImagePath, $now, $id]);

        return $stmt->rowCount() > 0;
    }

    public function modelExistsForDifferentId(int $id, int $supplierId, string $model): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM models WHERE id != ? AND supplier_id = ? AND model = ?');
        $stmt->execute([$id, $supplierId, $model]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function findModelBySupplierId(int $supplierId, string $model): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM models WHERE supplier_id = ? AND model = ?');
        $stmt->execute([$supplierId, $model]);
        return $stmt->fetch() ?: null;
    }

    public function deleteModel(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM models WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function whitelistAll(): array
    {
        return $this->pdo
            ->query('SELECT imei, supplier, model FROM whitelist ORDER BY imei')
            ->fetchAll();
    }

    public function whitelistGet(string $imei): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whitelist WHERE imei = ?');
        $stmt->execute([$imei]);
        return $stmt->fetch() ?: null;
    }

    public function whitelistRegister(string $imei, string $supplier, string $model): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('
            INSERT INTO whitelist (imei, supplier, model, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(imei) DO UPDATE SET
                supplier = excluded.supplier,
                model = excluded.model,
                updated_at = ?
        ');
        $stmt->execute([$imei, $supplier, $model, $now, $now, $now]);
    }

    public function whitelistUnregister(string $imei): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM whitelist WHERE imei = ?');
        $stmt->execute([$imei]);
    }

    public function appendTelemetry(string $imei, string $type, array $payload): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT INTO telemetry (imei, type, payload, recorded_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$imei, $type, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
    }

    public function appendEvent(string $imei, string $type, array $payload): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT INTO events (imei, type, payload, recorded_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$imei, $type, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
    }

    public function appendRaw(string $imei, array $payload): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT INTO raw_payloads (imei, payload, recorded_at) VALUES (?, ?, ?)');
        $stmt->execute([$imei, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
    }

    public function recentTelemetry(string $imei, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT type, payload, recorded_at FROM telemetry WHERE imei = ? ORDER BY id DESC LIMIT ?');
        $stmt->execute([$imei, $limit]);
        return $this->decodeRows($stmt->fetchAll());
    }

    public function recentEvents(string $imei, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT type, payload, recorded_at FROM events WHERE imei = ? ORDER BY id DESC LIMIT ?');
        $stmt->execute([$imei, $limit]);
        return $this->decodeRows($stmt->fetchAll());
    }

    public function recentRaw(string $imei, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT payload, recorded_at FROM raw_payloads WHERE imei = ? ORDER BY id DESC LIMIT ?');
        $stmt->execute([$imei, $limit]);
        return $this->decodeRows($stmt->fetchAll());
    }

    public function configurations(string $imei): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM device_configurations WHERE imei = ? ORDER BY config_key');
        $stmt->execute([$imei]);
        return array_map([$this, 'normalizeConfigurationRow'], $stmt->fetchAll());
    }

    public function saveDesiredConfiguration(
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

    public function markConfigurationApplyStatus(string $imei, string $key, string $status, string $commandId = ''): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('
            UPDATE device_configurations
            SET last_status = ?, last_command_id = ?, applied_at = ?
            WHERE imei = ? AND config_key = ?
        ');
        $stmt->execute([$status, $commandId, $now, $imei, $key]);
    }

    public function saveReportedConfiguration(
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

    private function decodeRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $decoded = json_decode($row['payload'], true);
            $row['payload'] = is_array($decoded) ? $decoded : $row['payload'];
            return $row;
        }, $rows);
    }

    private function normalizeConfigurationRow(array $row): array
    {
        $row['desired_payload'] = json_decode((string)($row['desired_payload'] ?? '{}'), true) ?: [];
        $row['reported_payload'] = json_decode((string)($row['reported_payload'] ?? '{}'), true) ?: [];
        return $row;
    }
}
