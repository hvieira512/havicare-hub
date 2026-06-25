CREATE TABLE IF NOT EXISTS suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL UNIQUE,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS models (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id BIGINT UNSIGNED NOT NULL,
    internal_model VARCHAR(191) NOT NULL,
    commercial_name VARCHAR(191) NOT NULL,
    device_type VARCHAR(32) NOT NULL DEFAULT 'watch',
    protocol VARCHAR(64) NOT NULL,
    image_path VARCHAR(255) NOT NULL DEFAULT '',
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_models_supplier_internal_model (supplier_id, internal_model),
    CONSTRAINT fk_models_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_request_capabilities (
    model_id BIGINT UNSIGNED NOT NULL,
    downlink_command VARCHAR(191) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (model_id, downlink_command),
    CONSTRAINT fk_model_request_capabilities_model FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whitelist (
    imei VARCHAR(64) NOT NULL PRIMARY KEY,
    supplier VARCHAR(191) NOT NULL,
    model VARCHAR(191) NOT NULL,
    device_type VARCHAR(32) NOT NULL DEFAULT 'watch',
    license_id VARCHAR(64) NOT NULL DEFAULT '0',
    sim_number VARCHAR(64) NOT NULL DEFAULT '',
    device_id VARCHAR(191) NOT NULL DEFAULT '',
    source_system VARCHAR(64) NOT NULL DEFAULT '',
    source_device_id VARCHAR(191) NOT NULL DEFAULT '',
    company VARCHAR(191) NOT NULL DEFAULT 'null',
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_configurations (
    imei VARCHAR(64) NOT NULL,
    config_key VARCHAR(191) NOT NULL,
    protocol VARCHAR(64) NOT NULL,
    supplier VARCHAR(191) NOT NULL DEFAULT '',
    model VARCHAR(191) NOT NULL DEFAULT '',
    command VARCHAR(191) NOT NULL DEFAULT '',
    desired_payload LONGTEXT NOT NULL DEFAULT '{}',
    reported_payload LONGTEXT NOT NULL DEFAULT '{}',
    last_status VARCHAR(64) NOT NULL DEFAULT '',
    last_command_id VARCHAR(64) NOT NULL DEFAULT '',
    desired_updated_at VARCHAR(32) NOT NULL DEFAULT '',
    reported_at VARCHAR(32) NOT NULL DEFAULT '',
    applied_at VARCHAR(32) NOT NULL DEFAULT '',
    PRIMARY KEY (imei, config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(64) NOT NULL,
    license_id VARCHAR(64) NOT NULL DEFAULT '',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL UNIQUE,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    license_id VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_licenses_company_license (company_id, license_id),
    CONSTRAINT fk_licenses_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_device_configurations_imei ON device_configurations(imei);
CREATE INDEX idx_model_request_capabilities_model ON model_request_capabilities(model_id);
CREATE INDEX idx_api_users_role_license ON api_users(role, license_id);
CREATE INDEX idx_licenses_company_id ON licenses(company_id);
CREATE INDEX idx_whitelist_device_type_license ON whitelist(device_type, license_id);
CREATE INDEX idx_whitelist_supplier_model ON whitelist(supplier, model);
CREATE INDEX idx_whitelist_company ON whitelist(company);
CREATE INDEX idx_whitelist_device_id ON whitelist(device_id);
CREATE INDEX idx_whitelist_source_alias ON whitelist(source_system, source_device_id);
