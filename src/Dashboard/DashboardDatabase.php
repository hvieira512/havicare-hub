<?php

namespace Hub\Dashboard;

use PDO;

final class DashboardDatabase
{
    private PDO $pdo;

    private const DEFAULT_SUPPLIERS = [
        'Wonlex',
        'Vivistar',
        '4P Touch',
        'Qinglanst',
        'Voerka',
    ];

    private const DEFAULT_MODELS = [
        ['Wonlex', 'HW20PRO', 'HW20PRO', 'watch', ''],
        ['Wonlex', 'L08 Pro', 'L08 Pro', 'watch', ''],
        ['Vivistar', 'L08 Pro', 'L08 Pro', 'watch', ''],
        ['4P Touch', 'D46', 'D46', 'watch', ''],
        ['Qinglanst', 'RD-V1', 'RD-V1', 'radar', ''],
    ];

    /**
     * @var list<array{0: string, 1: string}>
     */
    private const DEFAULT_SUPPLIER_DEVICE_TYPES = [
        ['Wonlex', 'watch'],
        ['Vivistar', 'watch'],
        ['4P Touch', 'watch'],
        ['Qinglanst', 'radar'],
        ['Voerka', 'ncs'],
    ];

    public function __construct(array $config)
    {
        $driver = strtolower(trim((string)($config['driver'] ?? '')));
        if ($driver !== 'mysql') {
            throw new \InvalidArgumentException('DashboardDatabase only supports the mysql driver');
        }

        $charset = trim((string)($config['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($config['host'] ?? '127.0.0.1'),
            (int)($config['port'] ?? 3306),
            (string)($config['name'] ?? 'hitecosystem_hub'),
            $charset,
        );
        $this->pdo = new PDO($dsn, (string)($config['username'] ?? ''), (string)($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->bootstrapSchema();
        $this->seedDefaults();
        $this->seedDefaultSupplierDeviceTypes();
        $this->seedDefaultCapabilities();
        $this->migrateModelCapabilitiesToCapabilityIds();
        $this->seedDefaultModelCapabilities();
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
            throw new \RuntimeException('database schema file is missing or empty');
        }

        $this->pdo->exec($schema);
        $this->migrateSchema();
        $this->ensureMysqlIndexes();
    }

    private function ensureMysqlIndexes(): void
    {
        $this->ensureMysqlIndex('device_configurations', 'idx_device_configurations_imei', 'imei');
        $this->ensureMysqlIndex('model_capabilities', 'idx_model_capabilities_model', 'model_id');
        $this->ensureMysqlIndex('model_capabilities', 'idx_model_capabilities_capability', 'capability_id');
        $this->ensureMysqlIndex('api_users', 'idx_api_users_role_license', 'role, license_id');
        $this->ensureMysqlIndex('licenses', 'idx_licenses_company_id', 'company_id');
        $this->ensureMysqlIndex('capabilities', 'idx_capabilities_device_type_section_order', 'device_type, section, sort_order');
        $this->ensureMysqlIndex('supplier_device_types', 'idx_supplier_device_types_device_type', 'device_type, supplier_id');
        $this->ensureMysqlIndex('whitelist', 'idx_whitelist_device_type_license', 'device_type, license_id');
        $this->ensureMysqlIndex('whitelist', 'idx_whitelist_supplier_model', 'supplier, model');
        $this->ensureMysqlIndex('whitelist', 'idx_whitelist_company', 'company');
        $this->ensureMysqlIndex('whitelist', 'idx_whitelist_device_id', 'device_id');
    }

    private function ensureMysqlIndex(string $table, string $indexName, string $columns): void
    {
        $stmt = $this->pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
        if ($stmt && $stmt->fetch()) {
            return;
        }

        $this->pdo->exec("CREATE INDEX `{$indexName}` ON `{$table}` ({$columns})");
    }

    private function seedDefaults(): void
    {
        foreach (self::DEFAULT_SUPPLIERS as $name) {
            $existing = $this->pdo->prepare('SELECT id FROM suppliers WHERE name = ?');
            $existing->execute([$name]);
            if ($existing->fetchColumn() !== false) {
                continue;
            }
            $insertSupplier = $this->pdo->prepare('INSERT INTO suppliers (name, enabled) VALUES (?, 1)');
            $insertSupplier->execute([$name]);
        }

        $nameToId = $this->pdo
            ->query("SELECT name, id FROM suppliers WHERE name IN ('" . implode("','", self::DEFAULT_SUPPLIERS) . "')")
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
                INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
                VALUES (?, ?, ?, ?, ?)
            ');
            $insertModel->execute([$nameToId[$row[0]], $row[1], $row[2], $row[3], $row[4]]);
        }

        $this->seedDefaultCompanies();
    }

    private function seedDefaultSupplierDeviceTypes(): void
    {
        $nameToId = $this->pdo
            ->query("SELECT name, id FROM suppliers WHERE name IN ('" . implode("','", self::DEFAULT_SUPPLIERS) . "')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $pairs = [];
        foreach (self::DEFAULT_SUPPLIER_DEVICE_TYPES as [$supplierName, $deviceType]) {
            $supplierId = (int)($nameToId[$supplierName] ?? 0);
            if ($supplierId <= 0) {
                continue;
            }
            $pairs[$supplierId . ':' . $deviceType] = [$supplierId, $deviceType];
        }

        $modelRows = $this->pdo
            ->query('SELECT DISTINCT supplier_id, device_type FROM models ORDER BY supplier_id, device_type')
            ->fetchAll();
        foreach ($modelRows as $row) {
            $supplierId = (int)($row['supplier_id'] ?? 0);
            $deviceType = (string)($row['device_type'] ?? '');
            if ($supplierId <= 0 || $deviceType === '') {
                continue;
            }
            $pairs[$supplierId . ':' . $deviceType] = [$supplierId, $deviceType];
        }

        if ($pairs === []) {
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO supplier_device_types (supplier_id, device_type)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
        ');
        foreach ($pairs as [$supplierId, $deviceType]) {
            $stmt->execute([$supplierId, $deviceType]);
        }
    }

    private function seedDefaultCompanies(): void
    {
        foreach (['hitCare', 'haviCare'] as $name) {
            $existing = $this->pdo->prepare('SELECT id FROM companies WHERE name = ?');
            $existing->execute([$name]);
            if ($existing->fetchColumn() !== false) {
                continue;
            }
            $insertCompany = $this->pdo->prepare('INSERT INTO companies (name) VALUES (?)');
            $insertCompany->execute([$name]);
        }

        $stmt = $this->pdo->prepare("SELECT id FROM companies WHERE name = ?");
        $stmt->execute(['hitCare']);
        $hitCareId = (int)($stmt->fetchColumn() ?: 0);
        if ($hitCareId > 0) {
            $existing = $this->pdo->prepare('SELECT id FROM licenses WHERE company_id = ? AND license_id = ?');
            $existing->execute([$hitCareId, '1001']);
            if ($existing->fetchColumn() === false) {
                $licenseStmt = $this->pdo->prepare('INSERT INTO licenses (company_id, license_id, name) VALUES (?, ?, ?)');
                $licenseStmt->execute([$hitCareId, '1001', 'gucc.dev']);
            }
        }
    }

    private function seedDefaultCapabilities(): void
    {
        $select = $this->pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $insert = $this->pdo->prepare('
            INSERT INTO capabilities (device_type, section, capability_key, label, is_telemetry, is_configurable, is_requestable, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $update = $this->pdo->prepare('
            UPDATE capabilities
            SET section = ?, label = ?, is_telemetry = ?, is_configurable = ?, is_requestable = ?, sort_order = ?
            WHERE device_type = ? AND capability_key = ?
        ');

        foreach (GenericModelCapabilityCatalog::definitions() as $definition) {
            $select->execute([$definition['deviceType'], $definition['key']]);
            if ($select->fetchColumn() === false) {
                $insert->execute([
                    $definition['deviceType'],
                    $definition['section'],
                    $definition['key'],
                    $definition['label'],
                    (int)$definition['isTelemetry'],
                    (int)$definition['isConfigurable'],
                    (int)$definition['isRequestable'],
                    $definition['sortOrder'],
                ]);
                continue;
            }

            $update->execute([
                $definition['section'],
                $definition['label'],
                (int)$definition['isTelemetry'],
                (int)$definition['isConfigurable'],
                (int)$definition['isRequestable'],
                $definition['sortOrder'],
                $definition['deviceType'],
                $definition['key'],
            ]);
        }
    }

    private function seedDefaultModelCapabilities(): void
    {
        $models = $this->pdo
            ->query('SELECT m.id, m.device_type, s.name AS supplier_name FROM models m JOIN suppliers s ON s.id = m.supplier_id ORDER BY m.id')
            ->fetchAll();

        if (!is_array($models) || $models === []) {
            return;
        }

        foreach ($models as $model) {
            $modelId = (int)($model['id'] ?? 0);
            $deviceType = DeviceMetadata::normalizeDeviceType((string)($model['device_type'] ?? 'watch'));
            $supplierName = (string)($model['supplier_name'] ?? '');
            if ($modelId <= 0 || $supplierName === '') {
                continue;
            }

            foreach (SupplierCapabilityTemplate::keysForSupplierDeviceType($supplierName, $deviceType) as $feature) {
                $capabilityId = $this->capabilityIdForDeviceTypeAndKey($deviceType, $feature);
                if ($capabilityId === null) {
                    continue;
                }

                $existing = $this->pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND capability_id = ?');
                $existing->execute([$modelId, $capabilityId]);
                if ((int)$existing->fetchColumn() > 0) {
                    continue;
                }
                $insert = $this->pdo->prepare('
                    INSERT INTO model_capabilities (model_id, capability_id, enabled)
                    VALUES (?, ?, 1)
                ');
                $insert->execute([$modelId, $capabilityId]);
            }
        }
    }

    private function migrateModelCapabilitiesToCapabilityIds(): void
    {
        if (!$this->tableExists('model_capabilities_legacy')) {
            return;
        }

        $rows = $this->pdo->query('SELECT model_id, feature, enabled, created_at, updated_at FROM model_capabilities_legacy ORDER BY model_id, feature')->fetchAll();
        if (!is_array($rows) || $rows === []) {
            return;
        }

        $insert = $this->pdo->prepare('
            INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $deviceTypeStmt = $this->pdo->prepare('SELECT device_type FROM models WHERE id = ?');

        $this->pdo->beginTransaction();
        foreach ($rows as $row) {
            $modelId = (int)($row['model_id'] ?? 0);
            $feature = (string)($row['feature'] ?? '');
            if ($modelId <= 0 || $feature === '') {
                continue;
            }

            $canonical = GenericModelCapabilityCatalog::normalizeStoredCapabilityKey($feature);
            if ($canonical === null) {
                continue;
            }

            $deviceTypeStmt->execute([$modelId]);
            $deviceType = DeviceMetadata::normalizeDeviceType((string)($deviceTypeStmt->fetchColumn() ?: 'watch'));
            $capabilityId = $this->capabilityIdForDeviceTypeAndKey($deviceType, $canonical);
            if ($capabilityId === null) {
                continue;
            }

            $insert->execute([
                $modelId,
                $capabilityId,
                (int)($row['enabled'] ?? 1),
                TimestampFormatter::toDatabase((string)($row['created_at'] ?? '')),
                TimestampFormatter::toDatabase((string)($row['updated_at'] ?? '')),
            ]);
        }
        $this->pdo->commit();
        $this->pdo->exec('DROP TABLE model_capabilities_legacy');
    }

    private function migrateSchema(): void
    {
        if ($this->tableExists('generic_capabilities') && !$this->tableExists('capabilities')) {
            $this->pdo->exec('RENAME TABLE generic_capabilities TO capabilities');
        }

        if ($this->columnExists('whitelist', 'source_device_id')) {
            $this->pdo->exec("UPDATE whitelist SET device_id = source_device_id WHERE device_id = '' AND source_device_id != ''");
        }

        $this->dropIndexIfExists('whitelist', 'idx_whitelist_source_alias');

        if ($this->columnExists('whitelist', 'source_system')) {
            $this->pdo->exec('ALTER TABLE whitelist DROP COLUMN source_system');
        }
        if ($this->columnExists('whitelist', 'source_device_id')) {
            $this->pdo->exec('ALTER TABLE whitelist DROP COLUMN source_device_id');
        }
        if ($this->columnExists('models', 'protocol')) {
            $this->pdo->exec('ALTER TABLE models DROP COLUMN protocol');
        }
        if ($this->tableExists('model_capabilities') && $this->columnExists('model_capabilities', 'feature')) {
            if ($this->tableExists('model_capabilities_legacy')) {
                $this->pdo->exec('DROP TABLE model_capabilities_legacy');
            }
            $this->pdo->exec('RENAME TABLE model_capabilities TO model_capabilities_legacy');
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS model_capabilities (
                    model_id BIGINT UNSIGNED NOT NULL,
                    capability_id BIGINT UNSIGNED NOT NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (model_id, capability_id),
                    CONSTRAINT fk_model_capabilities_model_v2 FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                    CONSTRAINT fk_model_capabilities_capability_v2 FOREIGN KEY (capability_id) REFERENCES capabilities(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }
        if ($this->tableExists('model_request_capabilities')) {
            $this->pdo->exec('DROP TABLE model_request_capabilities');
        }
        if ($this->tableExists('capabilities')) {
            if (!$this->columnExists('capabilities', 'device_type')) {
                $this->pdo->exec("ALTER TABLE capabilities ADD COLUMN device_type ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch' AFTER id");
            }
            if (!$this->columnExists('capabilities', 'is_telemetry')) {
                $this->pdo->exec('ALTER TABLE capabilities ADD COLUMN is_telemetry TINYINT(1) NOT NULL DEFAULT 0 AFTER label');
            }
            if (!$this->columnExists('capabilities', 'is_configurable')) {
                $this->pdo->exec('ALTER TABLE capabilities ADD COLUMN is_configurable TINYINT(1) NOT NULL DEFAULT 0 AFTER is_telemetry');
            }
            if (!$this->columnExists('capabilities', 'is_requestable')) {
                $this->pdo->exec('ALTER TABLE capabilities ADD COLUMN is_requestable TINYINT(1) NOT NULL DEFAULT 0 AFTER is_configurable');
            }
        }

        $this->pdo->exec("UPDATE models SET device_type = 'watch' WHERE device_type NOT IN ('watch', 'ncs', 'radar') OR device_type IS NULL");
        $this->pdo->exec("UPDATE whitelist SET device_type = 'watch' WHERE device_type NOT IN ('watch', 'ncs', 'radar') OR device_type IS NULL");
        $this->pdo->exec("UPDATE device_configurations SET last_status = '' WHERE last_status NOT IN ('', 'queued', 'waiting', 'acked', 'failed', 'dropped', 'sent') OR last_status IS NULL");
        if ($this->tableExists('capabilities')) {
            $this->pdo->exec("UPDATE capabilities SET device_type = 'watch' WHERE device_type NOT IN ('watch', 'ncs', 'radar') OR device_type IS NULL");
        }

        $this->migrateTimestampTables();

        $this->pdo->exec("ALTER TABLE models MODIFY COLUMN device_type ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch'");
        $this->pdo->exec("ALTER TABLE whitelist MODIFY COLUMN device_type ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch'");
        $this->pdo->exec("ALTER TABLE api_users MODIFY COLUMN role ENUM('hub_admin', 'license_client') NOT NULL");
        $this->pdo->exec("ALTER TABLE device_configurations MODIFY COLUMN last_status ENUM('', 'queued', 'waiting', 'acked', 'failed', 'dropped', 'sent') NOT NULL DEFAULT ''");
        if ($this->tableExists('capabilities')) {
            $this->dropIndexIfExists('capabilities', 'capability_key');
            $this->dropIndexIfExists('capabilities', 'uq_generic_capabilities_capability_key');
            $this->dropIndexIfExists('capabilities', 'idx_generic_capabilities_section_order');
            $this->pdo->exec("ALTER TABLE capabilities MODIFY COLUMN device_type ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch'");
        }
    }

    private function migrateTimestampTables(): void
    {
        foreach (['suppliers', 'models', 'supplier_device_types', 'capabilities', 'model_capabilities', 'whitelist', 'api_users', 'companies', 'licenses'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $type = strtolower((string)$this->columnType($table, 'created_at'));
            if ($type !== '' && str_starts_with($type, 'datetime')) {
                continue;
            }

            $this->pdo->exec("
                UPDATE `{$table}`
                SET
                    `created_at` = COALESCE(
                        DATE_FORMAT(STR_TO_DATE(`created_at`, '%Y-%m-%dT%H:%i:%sZ'), '%Y-%m-%d %H:%i:%s'),
                        DATE_FORMAT(STR_TO_DATE(`created_at`, '%Y-%m-%d %H:%i:%s'), '%Y-%m-%d %H:%i:%s'),
                        CURRENT_TIMESTAMP
                    ),
                    `updated_at` = COALESCE(
                        DATE_FORMAT(STR_TO_DATE(`updated_at`, '%Y-%m-%dT%H:%i:%sZ'), '%Y-%m-%d %H:%i:%s'),
                        DATE_FORMAT(STR_TO_DATE(`updated_at`, '%Y-%m-%d %H:%i:%s'), '%Y-%m-%d %H:%i:%s'),
                        CURRENT_TIMESTAMP
                    )
            ");

            $this->pdo->exec("
                ALTER TABLE `{$table}`
                    MODIFY COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    MODIFY COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ");
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);

        return $stmt->fetch() !== false;
    }

    private function columnType(string $table, string $column): ?string
    {
        if (!$this->tableExists($table)) {
            return null;
        }

        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        $row = $stmt->fetch();

        return is_array($row) ? strtolower((string)($row['Type'] ?? '')) : null;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);

        return $stmt->fetch() !== false;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        $stmt = $this->pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
        $stmt->execute([$indexName]);
        if ($stmt->fetch() === false) {
            return;
        }

        $this->pdo->exec("DROP INDEX `{$indexName}` ON `{$table}`");
    }

    private function capabilityIdForDeviceTypeAndKey(string $deviceType, string $key): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $stmt->execute([DeviceMetadata::normalizeDeviceType($deviceType), $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int)$value;
    }
}
