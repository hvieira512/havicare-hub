<?php

namespace Hub\Dashboard;

use Hub\Command\DeviceCommandCatalog;
use PDO;

final class DashboardDatabase
{
    private PDO $pdo;

    private const DEFAULT_MODELS = [
        ['Wonlex', 'HW20PRO', 'HW20PRO', 'watch', ''],
        ['Wonlex', 'L08 Pro', 'L08 Pro', 'watch', ''],
        ['Vivistar', 'L08 Pro', 'L08 Pro', 'watch', ''],
        ['4P Touch', 'D46', 'D46', 'watch', ''],
        ['Qinglanst', 'RD-V1', 'RD-V1', 'radar', ''],
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
        $this->seedDefaultGenericCapabilities();
        $this->migrateLegacyModelRequestCapabilities();
        $this->migrateModelCapabilitiesToGenericCatalog();
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
        $this->ensureMysqlIndex('api_users', 'idx_api_users_role_license', 'role, license_id');
        $this->ensureMysqlIndex('licenses', 'idx_licenses_company_id', 'company_id');
        $this->ensureMysqlIndex('generic_capabilities', 'idx_generic_capabilities_section_order', 'section, sort_order');
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
                INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $insertModel->execute([$nameToId[$row[0]], $row[1], $row[2], $row[3], $row[4], $now, $now]);
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

    private function seedDefaultGenericCapabilities(): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $select = $this->pdo->prepare('SELECT id FROM generic_capabilities WHERE capability_key = ?');
        $insert = $this->pdo->prepare('
            INSERT INTO generic_capabilities (section, capability_key, label, sort_order, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $update = $this->pdo->prepare('
            UPDATE generic_capabilities
            SET section = ?, label = ?, sort_order = ?, updated_at = ?
            WHERE capability_key = ?
        ');

        foreach (GenericModelCapabilityCatalog::definitions() as $definition) {
            $select->execute([$definition['key']]);
            if ($select->fetchColumn() === false) {
                $insert->execute([
                    $definition['section'],
                    $definition['key'],
                    $definition['label'],
                    $definition['sortOrder'],
                    $now,
                    $now,
                ]);
                continue;
            }

            $update->execute([
                $definition['section'],
                $definition['label'],
                $definition['sortOrder'],
                $now,
                $definition['key'],
            ]);
        }
    }

    private function migrateLegacyModelRequestCapabilities(): void
    {
        try {
            $this->pdo->query('SELECT COUNT(*) FROM model_request_capabilities');
        } catch (\Exception $e) {
            return;
        }

        $count = $this->pdo->query('SELECT COUNT(*) FROM model_capabilities')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $rows = $this->pdo->query('
            SELECT mrc.model_id, mrc.downlink_command, mrc.enabled, mrc.created_at, mrc.updated_at,
                   s.name AS supplier_name
            FROM model_request_capabilities mrc
            JOIN models m ON m.id = mrc.model_id
            JOIN suppliers s ON s.id = m.supplier_id
        ')->fetchAll();

        if (!is_array($rows) || $rows === []) {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $insert = $this->pdo->prepare('
            INSERT INTO model_capabilities (model_id, feature, enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
        ');

        foreach ($rows as $row) {
            $modelId = (int)($row['model_id'] ?? 0);
            $commandId = (string)($row['downlink_command'] ?? '');
            $protocol = DeviceProtocol::forSupplier((string)($row['supplier_name'] ?? ''));
            $enabled = (int)($row['enabled'] ?? 0);
            $createdAt = (string)($row['created_at'] ?? $now);

            if ($modelId <= 0 || $commandId === '' || $protocol === '') {
                continue;
            }

            $entry = DeviceCommandCatalog::requestForProtocol($protocol, $commandId);
            $feature = (string)($entry['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            $existing = $this->pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND feature = ?');
            $existing->execute([$modelId, $feature]);
            if ((int)$existing->fetchColumn() > 0) {
                continue;
            }

            $insert->execute([$modelId, $feature, $enabled, $createdAt, $now]);
        }
    }

    private function seedDefaultModelCapabilities(): void
    {
        $models = $this->pdo
            ->query('SELECT m.id, s.name AS supplier_name FROM models m JOIN suppliers s ON s.id = m.supplier_id ORDER BY m.id')
            ->fetchAll();

        if (!is_array($models) || $models === []) {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($models as $model) {
            $modelId = (int)($model['id'] ?? 0);
            $protocol = DeviceProtocol::forSupplier((string)($model['supplier_name'] ?? ''));
            if ($modelId <= 0 || $protocol === '') {
                continue;
            }

            foreach (GenericModelCapabilityCatalog::keysForProtocol($protocol) as $feature) {
                $existing = $this->pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND feature = ?');
                $existing->execute([$modelId, $feature]);
                if ((int)$existing->fetchColumn() > 0) {
                    continue;
                }
                $insert = $this->pdo->prepare('
                    INSERT INTO model_capabilities (model_id, feature, enabled, created_at, updated_at)
                    VALUES (?, ?, 1, ?, ?)
                ');
                $insert->execute([$modelId, $feature, $now, $now]);
            }
        }
    }

    private function migrateModelCapabilitiesToGenericCatalog(): void
    {
        $rows = $this->pdo->query('SELECT model_id, feature, enabled, created_at, updated_at FROM model_capabilities ORDER BY model_id, feature')->fetchAll();
        if (!is_array($rows) || $rows === []) {
            return;
        }

        $insert = $this->pdo->prepare('
            INSERT IGNORE INTO model_capabilities (model_id, feature, enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $delete = $this->pdo->prepare('DELETE FROM model_capabilities WHERE model_id = ? AND feature = ?');

        $this->pdo->beginTransaction();
        foreach ($rows as $row) {
            $modelId = (int)($row['model_id'] ?? 0);
            $feature = (string)($row['feature'] ?? '');
            if ($modelId <= 0 || $feature === '') {
                continue;
            }

            $canonical = GenericModelCapabilityCatalog::normalizeStoredCapabilityKey($feature);
            if ($canonical === null) {
                $delete->execute([$modelId, $feature]);
                continue;
            }

            if ($canonical !== $feature) {
                $insert->execute([
                    $modelId,
                    $canonical,
                    (int)($row['enabled'] ?? 1),
                    (string)($row['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
                    (string)($row['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
                ]);
                $delete->execute([$modelId, $feature]);
            }
        }
        $this->pdo->commit();
    }

    private function migrateSchema(): void
    {
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

        $this->pdo->exec("UPDATE models SET device_type = 'watch' WHERE device_type NOT IN ('watch', 'ncs', 'radar') OR device_type IS NULL");
        $this->pdo->exec("UPDATE whitelist SET device_type = 'watch' WHERE device_type NOT IN ('watch', 'ncs', 'radar') OR device_type IS NULL");
        $this->pdo->exec("UPDATE device_configurations SET last_status = '' WHERE last_status NOT IN ('', 'queued', 'waiting', 'acked', 'failed', 'dropped', 'sent') OR last_status IS NULL");

        $this->pdo->exec("ALTER TABLE models MODIFY COLUMN device_type ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch'");
        $this->pdo->exec("ALTER TABLE whitelist MODIFY COLUMN device_type ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch'");
        $this->pdo->exec("ALTER TABLE api_users MODIFY COLUMN role ENUM('hub_admin', 'license_client') NOT NULL");
        $this->pdo->exec("ALTER TABLE device_configurations MODIFY COLUMN last_status ENUM('', 'queued', 'waiting', 'acked', 'failed', 'dropped', 'sent') NOT NULL DEFAULT ''");
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);

        return $stmt->fetch() !== false;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $stmt = $this->pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
        $stmt->execute([$indexName]);
        if ($stmt->fetch() === false) {
            return;
        }

        $this->pdo->exec("DROP INDEX `{$indexName}` ON `{$table}`");
    }
}
