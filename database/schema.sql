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
    device_type ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch',
    image_path VARCHAR(255) NOT NULL DEFAULT '',
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_models_supplier_internal_model (supplier_id, internal_model),
    CONSTRAINT fk_models_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS capabilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_type ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch',
    section VARCHAR(64) NOT NULL,
    capability_key VARCHAR(191) NOT NULL,
    label VARCHAR(191) NOT NULL,
    is_telemetry TINYINT(1) NOT NULL DEFAULT 0,
    is_configurable TINYINT(1) NOT NULL DEFAULT 0,
    is_requestable TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_capabilities_device_type_key (device_type, capability_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_capabilities (
    model_id BIGINT UNSIGNED NOT NULL,
    capability_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (model_id, capability_id),
    CONSTRAINT fk_model_capabilities_model_v2 FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    CONSTRAINT fk_model_capabilities_capability_v2 FOREIGN KEY (capability_id) REFERENCES capabilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whitelist (
    imei VARCHAR(64) NOT NULL PRIMARY KEY,
    supplier VARCHAR(191) NOT NULL,
    model VARCHAR(191) NOT NULL,
    device_type ENUM('watch', 'ncs', 'radar') NOT NULL DEFAULT 'watch',
    license_id INT UNSIGNED NOT NULL DEFAULT 0,
    sim_number VARCHAR(64) NOT NULL DEFAULT '',
    device_id VARCHAR(191) NOT NULL DEFAULT '',
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
    desired_payload LONGTEXT NOT NULL,
    reported_payload LONGTEXT NOT NULL,
    last_status ENUM('', 'queued', 'waiting', 'acked', 'failed', 'dropped', 'sent') NOT NULL DEFAULT '',
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
    role ENUM('hub_admin', 'license_client') NOT NULL,
    license_id INT UNSIGNED NOT NULL DEFAULT 0,
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
    license_id INT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_licenses_company_license (company_id, license_id),
    CONSTRAINT fk_licenses_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
