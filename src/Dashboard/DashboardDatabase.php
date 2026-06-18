<?php

namespace Hub\Dashboard;

use PDO;

final class DashboardDatabase
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

        $this->bootstrapSchema();
        $this->seedDefaults();
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
}
