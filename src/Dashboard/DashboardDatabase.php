<?php

namespace Hub\Dashboard;

use Hub\Command\DeviceCommandCatalog;
use PDO;

final class DashboardDatabase
{
    private PDO $pdo;
    private string $driver;

    private const DEFAULT_MODELS = [
        ['Wonlex', 'HW20PRO', 'HW20PRO', 'watch', 'wonlex-json', ''],
        ['Wonlex', 'L08 Pro', 'L08 Pro', 'watch', 'wonlex-json', ''],
        ['Vivistar', 'L08 Pro', 'L08 Pro', 'watch', 'vivistar-iw', ''],
        ['Vivistar', 'VIVISTAR-CARE', 'VIVISTAR-CARE', 'watch', 'vivistar-iw', ''],
        ['Vivistar', 'VIVISTAR-LITE', 'VIVISTAR-LITE', 'watch', 'vivistar-iw', ''],
        ['4P Touch', '4P-TOUCH', '4P-TOUCH', 'watch', 'four-p-touch', ''],
        ['4P Touch', 'D46', 'D46', 'watch', 'four-p-touch', ''],
        ['Qinglanst', 'RD-V1', 'RD-V1', 'radar', 'qinglanst', ''],
    ];

    public function __construct(array|string|null $config = null)
    {
        if (is_string($config) || $config === null) {
            $path = $config ?: (__DIR__ . '/../../var/db/dashboard.sqlite');
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $this->driver = 'sqlite';
            $this->pdo = new PDO("sqlite:{$path}", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
        } else {
            $driver = strtolower(trim((string)($config['driver'] ?? 'sqlite')));
            if ($driver === 'mysql') {
                $charset = trim((string)($config['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    (string)($config['host'] ?? '127.0.0.1'),
                    (int)($config['port'] ?? 3306),
                    (string)($config['name'] ?? 'hitecosystem_hub'),
                    $charset,
                );
                $this->driver = 'mysql';
                $this->pdo = new PDO($dsn, (string)($config['username'] ?? ''), (string)($config['password'] ?? ''), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } else {
                $path = (string)($config['path'] ?? (__DIR__ . '/../../var/db/dashboard.sqlite'));
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                $this->driver = 'sqlite';
                $this->pdo = new PDO("sqlite:{$path}", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $this->pdo->exec('PRAGMA journal_mode=WAL');
                $this->pdo->exec('PRAGMA foreign_keys=ON');
            }
        }

        $this->bootstrapSchema();
        $this->seedDefaults();
        $this->seedDefaultModelRequestCapabilities();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    private function bootstrapSchema(): void
    {
        $schemaPath = $this->driver === 'mysql'
            ? __DIR__ . '/../../database/schema.mysql.sql'
            : __DIR__ . '/../../database/schema.sql';
        $schema = file_get_contents($schemaPath);
        if (!is_string($schema) || trim($schema) === '') {
            throw new \RuntimeException('database schema file is missing or empty');
        }

        $this->pdo->exec($schema);
        $this->dropLegacyHistoryStorage();

        if ($this->driver === 'mysql') {
            $this->ensureMysqlIndexes();
        }

        if ($this->driver === 'sqlite') {
            // Compatibility for databases created before device type and license support.
            $this->ensureColumn('whitelist', 'device_type', 'TEXT NOT NULL DEFAULT "watch"');
            $this->ensureColumn('whitelist', 'license_id', 'TEXT NOT NULL DEFAULT "0"');
            $this->ensureColumn('whitelist', 'sim_number', 'TEXT NOT NULL DEFAULT ""');
            $this->ensureColumn('whitelist', 'device_id', 'TEXT NOT NULL DEFAULT ""');
            $this->ensureColumn('whitelist', 'source_system', 'TEXT NOT NULL DEFAULT ""');
            $this->ensureColumn('whitelist', 'source_device_id', 'TEXT NOT NULL DEFAULT ""');
            $this->ensureColumn('whitelist', 'company', 'TEXT NOT NULL DEFAULT "null"');
            $this->ensureColumn('models', 'internal_model', 'TEXT NOT NULL DEFAULT ""');
            $this->ensureColumn('models', 'commercial_name', 'TEXT NOT NULL DEFAULT ""');
            $this->ensureColumn('models', 'device_type', 'TEXT NOT NULL DEFAULT "watch"');
            $this->migrateModelCatalogColumns();
        }
    }

    private function ensureMysqlIndexes(): void
    {
        $this->ensureMysqlIndex('device_configurations', 'idx_device_configurations_imei', 'imei');
        $this->ensureMysqlIndex('model_request_capabilities', 'idx_model_request_capabilities_model', 'model_id');
        $this->ensureMysqlIndex('api_users', 'idx_api_users_role_license', 'role, license_id');
        $this->ensureMysqlIndex('licenses', 'idx_licenses_company_id', 'company_id');
        $this->ensureMysqlIndex('whitelist', 'idx_whitelist_device_type_license', 'device_type, license_id');
        $this->ensureMysqlIndex('whitelist', 'idx_whitelist_supplier_model', 'supplier, model');
        $this->ensureMysqlIndex('whitelist', 'idx_whitelist_company', 'company');
        $this->ensureMysqlIndex('whitelist', 'idx_whitelist_device_id', 'device_id');
        $this->ensureMysqlIndex('whitelist', 'idx_whitelist_source_alias', 'source_system, source_device_id');
    }

    private function ensureMysqlIndex(string $table, string $indexName, string $columns): void
    {
        $stmt = $this->pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
        if ($stmt && $stmt->fetch()) {
            return;
        }

        $this->pdo->exec("CREATE INDEX `{$indexName}` ON `{$table}` ({$columns})");
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
        if ($this->driver !== 'sqlite') {
            return;
        }

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

    private function migrateModelCatalogColumns(): void
    {
        $modelColumns = $this->tableColumnNames('models');
        if (in_array('model', $modelColumns, true)) {
            $this->pdo->exec("UPDATE models SET internal_model = model WHERE trim(COALESCE(internal_model, '')) = ''");
            $this->pdo->exec("UPDATE models SET commercial_name = model WHERE trim(COALESCE(commercial_name, '')) = ''");
            try {
                $this->pdo->exec('ALTER TABLE models DROP COLUMN model');
            } catch (\Exception $e) {
            }
        }

        if (in_array('device_type', $modelColumns, true)) {
            $supplierColumns = $this->tableColumnNames('suppliers');
            if (in_array('device_type', $supplierColumns, true)) {
                $this->pdo->exec("
                    UPDATE models
                    SET device_type = (
                        SELECT COALESCE(NULLIF(trim(suppliers.device_type), ''), 'watch')
                        FROM suppliers
                        WHERE suppliers.id = models.supplier_id
                    )
                    WHERE trim(COALESCE(device_type, '')) = '' OR device_type = 'watch'
                ");
            }

            $this->pdo->exec("
                UPDATE models
                SET device_type = 'radar'
                WHERE protocol = 'qinglanst' AND (trim(COALESCE(device_type, '')) = '' OR device_type = 'watch')
            ");
            $this->pdo->exec("
                UPDATE models
                SET device_type = 'ncs'
                WHERE protocol = 'voerka-ncs' AND (trim(COALESCE(device_type, '')) = '' OR device_type = 'watch')
            ");
        }
    }

    /**
     * @return list<string>
     */
    private function tableColumnNames(string $table): array
    {
        $stmt = $this->pdo->query(sprintf('PRAGMA table_info(%s)', $table));
        $columns = $stmt ? $stmt->fetchAll() : [];

        return array_values(array_filter(array_map(
            static fn (array $column): ?string => isset($column['name']) ? (string)$column['name'] : null,
            $columns
        )));
    }

    private function seedDefaults(): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $seen = [];
        foreach (self::DEFAULT_MODELS as $row) {
            $name = $row[0];
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $existing = $this->pdo->prepare('SELECT id FROM suppliers WHERE name = ?');
            $existing->execute([$name]);
            if ($existing->fetchColumn() !== false) {
                continue;
            }
            $insertSupplier = $this->pdo->prepare('INSERT INTO suppliers (name, enabled, created_at, updated_at) VALUES (?, 1, ?, ?)');
            $insertSupplier->execute([$name, $now, $now]);
        }

        $nameToId = $this->pdo
            ->query("SELECT name, id FROM suppliers WHERE name IN ('" . implode("','", array_keys($seen)) . "')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach (self::DEFAULT_MODELS as $row) {
            if (!isset($nameToId[$row[0]])) {
                throw new \RuntimeException("Default supplier '{$row[0]}' was not created");
            }

            $existing = $this->pdo->prepare('SELECT id FROM models WHERE supplier_id = ? AND internal_model = ?');
            $existing->execute([(int)$nameToId[$row[0]], $row[1]]);
            if ($existing->fetchColumn() !== false) {
                continue;
            }

            $insertModel = $this->pdo->prepare('
                INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, protocol, image_path, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insertModel->execute([$nameToId[$row[0]], $row[1], $row[2], $row[3], $row[4], $row[5], $now, $now]);
        }

        $this->seedDefaultCompanies();
    }

    private function seedDefaultCompanies(): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach (['hitCare', 'haviCare'] as $name) {
            $existing = $this->pdo->prepare('SELECT id FROM companies WHERE name = ?');
            $existing->execute([$name]);
            if ($existing->fetchColumn() !== false) {
                continue;
            }
            $insertCompany = $this->pdo->prepare('INSERT INTO companies (name, created_at, updated_at) VALUES (?, ?, ?)');
            $insertCompany->execute([$name, $now, $now]);
        }

        $stmt = $this->pdo->prepare("SELECT id FROM companies WHERE name = ?");
        $stmt->execute(['hitCare']);
        $hitCareId = (int)($stmt->fetchColumn() ?: 0);
        if ($hitCareId > 0) {
            $existing = $this->pdo->prepare('SELECT id FROM licenses WHERE company_id = ? AND license_id = ?');
            $existing->execute([$hitCareId, '1001']);
            if ($existing->fetchColumn() === false) {
                $licenseStmt = $this->pdo->prepare('INSERT INTO licenses (company_id, license_id, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
                $licenseStmt->execute([$hitCareId, '1001', 'gucc.dev', $now, $now]);
            }
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

        $now = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($models as $model) {
            $modelId = (int)($model['id'] ?? 0);
            $protocol = (string)($model['protocol'] ?? '');
            if ($modelId <= 0 || $protocol === '') {
                continue;
            }

            foreach ($this->requestCommandsForProtocol($protocol) as $command) {
                $existing = $this->pdo->prepare('SELECT COUNT(*) FROM model_request_capabilities WHERE model_id = ? AND downlink_command = ?');
                $existing->execute([$modelId, $command]);
                if ((int)$existing->fetchColumn() > 0) {
                    continue;
                }
                $insert = $this->pdo->prepare('
                    INSERT INTO model_request_capabilities (model_id, downlink_command, enabled, created_at, updated_at)
                    VALUES (?, ?, 1, ?, ?)
                ');
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
