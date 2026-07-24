<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026072401UpgradeLegacySchema implements Migration
{
    public function version(): string
    {
        return '2026072401_upgrade_legacy_schema';
    }

    public function up(PDO $pdo): void
    {
        $schema = new MysqlSchema($pdo);
        if ($schema->hasTable('generic_capabilities')) {
            $pdo->exec('DROP TABLE generic_capabilities');
        }

        $schema->addColumn('models', 'image_path', "VARCHAR(255) NOT NULL DEFAULT '' AFTER device_type");
        $schema->addColumn('supplier_device_types', 'enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER supplier_id');
        $schema->addColumn('supplier_device_types', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER device_type');
        $schema->addColumn('supplier_device_types', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

        $schema->addColumn('whitelist', 'device_type', "ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch' AFTER model");
        $schema->addColumn('whitelist', 'license_id', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER device_type');
        $schema->addColumn('whitelist', 'sim_number', "VARCHAR(64) NOT NULL DEFAULT '' AFTER license_id");
        $schema->addColumn('whitelist', 'device_id', "VARCHAR(191) NOT NULL DEFAULT '' AFTER sim_number");
        $schema->addColumn('whitelist', 'company', "VARCHAR(191) NOT NULL DEFAULT 'null' AFTER device_id");

        $schema->addColumn('api_users', 'role', "ENUM('hub_admin', 'license_client') NOT NULL DEFAULT 'hub_admin' AFTER password_hash");
        $schema->addColumn('api_users', 'license_id', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER role');
        $schema->addColumn('api_users', 'enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER license_id');
        $schema->addColumn('api_users', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER enabled');
        $schema->addColumn('api_users', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

        $schema->addColumn('licenses', 'name', "VARCHAR(191) NOT NULL DEFAULT '' AFTER license_id");
        $schema->addColumn('licenses', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER name');
        $schema->addColumn('licenses', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
        $schema->addColumn('companies', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER name');
        $schema->addColumn('companies', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

        $schema->addColumn('capabilities', 'device_type', "ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch' AFTER id");
        $schema->addColumn('capabilities', 'section', "VARCHAR(64) NOT NULL DEFAULT 'telemetry' AFTER device_type");
        $schema->addColumn('capabilities', 'capability_key', "VARCHAR(191) NOT NULL DEFAULT '' AFTER section");
        $schema->addColumn('capabilities', 'label', "VARCHAR(191) NOT NULL DEFAULT '' AFTER capability_key");
        $schema->addColumn('capabilities', 'is_telemetry', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER label');
        $schema->addColumn('capabilities', 'is_configurable', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_telemetry');
        $schema->addColumn('capabilities', 'is_requestable', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_configurable');
        $schema->addColumn('capabilities', 'sort_order', 'INT NOT NULL DEFAULT 0 AFTER is_requestable');
        $schema->addColumn('capabilities', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER sort_order');
        $schema->addColumn('capabilities', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

        $this->normalizeTimestampColumns($pdo, $schema);
        $this->normalizeScalarColumns($pdo, $schema);
        $pdo->exec("
            ALTER TABLE api_users
            MODIFY role ENUM('hub_admin', 'license_client') NOT NULL
        ");
        $pdo->exec("ALTER TABLE licenses MODIFY name VARCHAR(191) NOT NULL");

        $schema->addIndex('model_capabilities', 'idx_model_capabilities_model', 'model_id');
        $schema->addIndex('api_users', 'idx_api_users_role_license', 'role, license_id');
        $schema->addIndex('licenses', 'idx_licenses_company_id', 'company_id');
        $schema->addIndex('capabilities', 'idx_capabilities_device_type_section_order', 'device_type, section, sort_order');
        $schema->addIndex('supplier_device_types', 'idx_supplier_device_types_device_type', 'device_type, supplier_id');
        $schema->addIndex('whitelist', 'idx_whitelist_device_type_license', 'device_type, license_id');
        $schema->addIndex('whitelist', 'idx_whitelist_supplier_model', 'supplier, model');
        $schema->addIndex('whitelist', 'idx_whitelist_company', 'company');
        $schema->addIndex('whitelist', 'idx_whitelist_device_id', 'device_id');
    }

    private function normalizeTimestampColumns(PDO $pdo, MysqlSchema $schema): void
    {
        $tables = [
            'suppliers',
            'models',
            'supplier_device_types',
            'capabilities',
            'model_capabilities',
            'whitelist',
            'api_users',
            'companies',
            'licenses',
        ];

        foreach ($tables as $table) {
            foreach (['created_at', 'updated_at'] as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    continue;
                }
                $pdo->exec("
                    UPDATE `{$table}`
                    SET `{$column}` = NULLIF(REPLACE(REPLACE(`{$column}`, 'T', ' '), 'Z', ''), '')
                    WHERE `{$column}` IS NOT NULL
                ");
                $onUpdate = $column === 'updated_at' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
                $pdo->exec("
                    ALTER TABLE `{$table}`
                    MODIFY `{$column}` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP{$onUpdate}
                ");
            }
        }
    }

    private function normalizeScalarColumns(PDO $pdo, MysqlSchema $schema): void
    {
        if ($schema->hasColumn('whitelist', 'license_id')) {
            $pdo->exec("UPDATE whitelist SET license_id = 0 WHERE license_id IS NULL OR license_id = ''");
            $pdo->exec('ALTER TABLE whitelist MODIFY license_id INT UNSIGNED NOT NULL DEFAULT 0');
        }
        if ($schema->hasColumn('api_users', 'license_id')) {
            $pdo->exec("UPDATE api_users SET license_id = 0 WHERE license_id IS NULL OR license_id = ''");
            $pdo->exec('ALTER TABLE api_users MODIFY license_id INT UNSIGNED NOT NULL DEFAULT 0');
        }
        if ($schema->hasColumn('licenses', 'license_id')) {
            $pdo->exec("UPDATE licenses SET license_id = 0 WHERE license_id IS NULL OR license_id = ''");
            $pdo->exec('ALTER TABLE licenses MODIFY license_id INT UNSIGNED NOT NULL');
        }
    }
}
