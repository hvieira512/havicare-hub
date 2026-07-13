<?php

namespace Hub\Infrastructure\Persistence;

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
        $this->normalizePersistedModelCapabilities();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function bootstrapSchema(): void
    {
        $schemaPath = __DIR__ . '/../../../database/schema.sql';
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
        foreach (['hitcare', 'havicare'] as $name) {
            $existing = $this->pdo->prepare('SELECT id FROM companies WHERE name = ?');
            $existing->execute([$name]);
            if ($existing->fetchColumn() !== false) {
                continue;
            }
            $insertCompany = $this->pdo->prepare('INSERT INTO companies (name) VALUES (?)');
            $insertCompany->execute([$name]);
        }

        $stmt = $this->pdo->prepare("SELECT id FROM companies WHERE name = ?");
        $stmt->execute(['hitcare']);
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
            WHERE id = ?
        ');

        foreach (\Hub\Domain\GenericModelCapabilityCatalog::definitions() as $definition) {
            $deviceType = (string)($definition['deviceType'] ?? 'watch');
            $key = (string)($definition['key'] ?? '');
            $section = (string)($definition['section'] ?? '');
            $label = (string)($definition['label'] ?? '');
            $sortOrder = (int)($definition['sortOrder'] ?? 0);
            $isTelemetry = !empty($definition['isTelemetry']) ? 1 : 0;
            $isConfigurable = !empty($definition['isConfigurable']) ? 1 : 0;
            $isRequestable = !empty($definition['isRequestable']) ? 1 : 0;

            if ($key === '' || $section === '' || $label === '') {
                continue;
            }

            $select->execute([$deviceType, $key]);
            $existingId = $select->fetchColumn();
            if ($existingId !== false) {
                $update->execute([$section, $label, $isTelemetry, $isConfigurable, $isRequestable, $sortOrder, $existingId]);
                continue;
            }

            $insert->execute([$deviceType, $section, $key, $label, $isTelemetry, $isConfigurable, $isRequestable, $sortOrder]);
        }
    }

    private function migrateModelCapabilitiesToCapabilityIds(): void
    {
        $columnCheck = $this->pdo->query("SHOW COLUMNS FROM model_capabilities LIKE 'capability_key'");
        if (!$columnCheck || !$columnCheck->fetch()) {
            return;
        }

        $this->pdo->exec('
            UPDATE model_capabilities mc
            JOIN capabilities c ON c.capability_key = mc.capability_key
                AND c.device_type = (
                    SELECT m.device_type
                    FROM models m
                    WHERE m.id = mc.model_id
                )
            SET mc.capability_id = c.id
            WHERE mc.capability_id IS NULL
        ');

        $this->pdo->exec('ALTER TABLE model_capabilities DROP COLUMN capability_key');
    }

    private function seedDefaultModelCapabilities(): void
    {
        $modelsStmt = $this->pdo->prepare('
            SELECT m.id, m.device_type, s.name AS supplier_name
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE m.device_type = ?
        ');
        $modelCapabilityCount = $this->pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ?');
        $capabilitySelect = $this->pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $existing = $this->pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND capability_id = ?');
        $insert = $this->pdo->prepare('INSERT INTO model_capabilities (model_id, capability_id, enabled) VALUES (?, ?, 1)');

        foreach (\Hub\Domain\GenericModelCapabilityCatalog::deviceTypes() as $deviceType) {
            $modelsStmt->execute([$deviceType]);
            $models = $modelsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($models === []) {
                continue;
            }

            foreach ($models as $model) {
                $modelId = (int)($model['id'] ?? 0);
                $supplierName = (string)($model['supplier_name'] ?? '');
                if ($modelId <= 0 || $supplierName === '') {
                    continue;
                }

                $modelCapabilityCount->execute([$modelId]);
                if ((int)($modelCapabilityCount->fetchColumn() ?: 0) > 0) {
                    continue;
                }

                foreach (\Hub\Domain\SupplierCapabilityTemplate::keysForSupplierDeviceType($supplierName, $deviceType) as $key) {
                    $capabilitySelect->execute([$deviceType, $key]);
                    $capabilityId = (int)($capabilitySelect->fetchColumn() ?: 0);
                    if ($capabilityId <= 0) {
                        continue;
                    }

                    $existing->execute([$modelId, $capabilityId]);
                    if ((int)$existing->fetchColumn() > 0) {
                        continue;
                    }

                    $insert->execute([$modelId, $capabilityId]);
                }
            }
        }
    }

    private function normalizePersistedModelCapabilities(): void
    {
        $modelsStmt = $this->pdo->query('
            SELECT m.id, m.device_type, s.name AS supplier_name
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
        ');
        if (!$modelsStmt) {
            return;
        }

        $currentStmt = $this->pdo->prepare('
            SELECT mc.capability_id, c.capability_key
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE mc.model_id = ? AND mc.enabled = 1
        ');
        $delete = $this->pdo->prepare('DELETE FROM model_capabilities WHERE model_id = ? AND capability_id = ?');
        $allowedCache = [];

        while ($model = $modelsStmt->fetch(PDO::FETCH_ASSOC)) {
            $modelId = (int)($model['id'] ?? 0);
            $supplierName = trim((string)($model['supplier_name'] ?? ''));
            $deviceType = (string)($model['device_type'] ?? 'watch');
            if ($modelId <= 0 || $supplierName === '') {
                continue;
            }

            $cacheKey = $supplierName . ':' . $deviceType;
            if (!array_key_exists($cacheKey, $allowedCache)) {
                $allowedCache[$cacheKey] = array_flip(
                    \Hub\Domain\SupplierCapabilityTemplate::keysForSupplierDeviceType($supplierName, $deviceType)
                );
            }

            $allowed = $allowedCache[$cacheKey];
            if ($allowed === []) {
                continue;
            }

            $currentStmt->execute([$modelId]);
            $rows = $currentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $capabilityId = (int)($row['capability_id'] ?? 0);
                $capabilityKey = (string)($row['capability_key'] ?? '');
                if ($capabilityId <= 0 || isset($allowed[$capabilityKey])) {
                    continue;
                }

                $delete->execute([$modelId, $capabilityId]);
            }
        }
    }

    private function supplierIdForName(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM suppliers WHERE name = ?');
        $stmt->execute([$name]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function migrateSchema(): void
    {
        $this->ensureColumn('models', 'image_path', 'VARCHAR(255) NOT NULL DEFAULT \'\' AFTER device_type');
        $this->ensureColumn('supplier_device_types', 'enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER supplier_id');
        $this->ensureColumn('supplier_device_types', 'created_at', 'DATETIME NULL DEFAULT NULL AFTER device_type');
        $this->ensureColumn('supplier_device_types', 'updated_at', 'DATETIME NULL DEFAULT NULL AFTER created_at');

        $this->ensureColumn('whitelist', 'device_type', "VARCHAR(32) NOT NULL DEFAULT 'watch' AFTER model");
        $this->ensureColumn('whitelist', 'license_id', 'INT NOT NULL DEFAULT 0 AFTER device_type');
        $this->ensureColumn('whitelist', 'sim_number', "VARCHAR(64) NOT NULL DEFAULT '' AFTER license_id");
        $this->ensureColumn('whitelist', 'device_id', "VARCHAR(64) NOT NULL DEFAULT '' AFTER sim_number");
        $this->ensureColumn('whitelist', 'company', "VARCHAR(255) NOT NULL DEFAULT 'null' AFTER device_id");

        $this->ensureColumn('api_users', 'role', "VARCHAR(32) NOT NULL DEFAULT 'hub_admin' AFTER password_hash");
        $this->ensureColumn('api_users', 'license_id', 'INT NOT NULL DEFAULT 0 AFTER role');
        $this->ensureColumn('api_users', 'enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER license_id');
        $this->ensureColumn('api_users', 'created_at', 'DATETIME NULL DEFAULT NULL AFTER enabled');
        $this->ensureColumn('api_users', 'updated_at', 'DATETIME NULL DEFAULT NULL AFTER created_at');

        $this->ensureColumn('licenses', 'name', "VARCHAR(255) NOT NULL DEFAULT '' AFTER license_id");
        $this->ensureColumn('licenses', 'created_at', 'DATETIME NULL DEFAULT NULL AFTER name');
        $this->ensureColumn('licenses', 'updated_at', 'DATETIME NULL DEFAULT NULL AFTER created_at');

        $this->ensureColumn('companies', 'created_at', 'DATETIME NULL DEFAULT NULL AFTER name');
        $this->ensureColumn('companies', 'updated_at', 'DATETIME NULL DEFAULT NULL AFTER created_at');

        $this->ensureColumn('capabilities', 'device_type', "VARCHAR(32) NOT NULL DEFAULT 'watch' AFTER id");
        $this->ensureColumn('capabilities', 'section', "VARCHAR(64) NOT NULL DEFAULT 'telemetry' AFTER device_type");
        $this->ensureColumn('capabilities', 'capability_key', "VARCHAR(128) NOT NULL DEFAULT '' AFTER section");
        $this->ensureColumn('capabilities', 'label', "VARCHAR(255) NOT NULL DEFAULT '' AFTER capability_key");
        $this->ensureColumn('capabilities', 'is_telemetry', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER label');
        $this->ensureColumn('capabilities', 'is_configurable', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_telemetry');
        $this->ensureColumn('capabilities', 'is_requestable', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_configurable');
        $this->ensureColumn('capabilities', 'sort_order', 'INT NOT NULL DEFAULT 0 AFTER is_requestable');
        $this->ensureColumn('capabilities', 'created_at', 'DATETIME NULL DEFAULT NULL AFTER sort_order');
        $this->ensureColumn('capabilities', 'updated_at', 'DATETIME NULL DEFAULT NULL AFTER created_at');

        $this->ensureColumn('model_capabilities', 'capability_id', 'INT NOT NULL DEFAULT 0 AFTER model_id');
        $this->ensureColumn('model_capabilities', 'enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER capability_id');
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if ($stmt && $stmt->fetch()) {
            return;
        }

        $this->pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}
