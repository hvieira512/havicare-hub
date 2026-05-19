CREATE DATABASE IF NOT EXISTS health_watches
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50)  NOT NULL,
    enabled     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS models (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id  INT UNSIGNED NOT NULL,
    code         VARCHAR(50)  NOT NULL,
    name         VARCHAR(255) NOT NULL,
    protocol     VARCHAR(100) NOT NULL,
    transport    VARCHAR(100) NOT NULL,
    enabled      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_models_code (code),
    KEY idx_models_supplier (supplier_id),
    KEY idx_models_protocol_transport (protocol, transport),
    CONSTRAINT fk_models_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS features (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(64)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    enabled     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_features_code (code),
    KEY idx_features_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_feature_mappings (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_id             INT UNSIGNED NOT NULL,
    feature_id           INT UNSIGNED NULL,
    native_type          VARCHAR(80)  NOT NULL COMMENT 'AP02/BP16/upLocation/dnLocation...',
    is_active            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0=passive upload, 1=active command',
    description          VARCHAR(255) NULL,
    enabled              TINYINT(1)   NOT NULL DEFAULT 1,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_model_native_type (model_id, native_type),
    KEY idx_mapping_feature (feature_id),
    KEY idx_mapping_model_feature (model_id, feature_id),
    KEY idx_mapping_active (model_id, is_active),
    CONSTRAINT fk_mapping_model
        FOREIGN KEY (model_id) REFERENCES models(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_mapping_feature
        FOREIGN KEY (feature_id) REFERENCES features(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
    imei          VARCHAR(15)  NOT NULL PRIMARY KEY,
    model_id      INT UNSIGNED NOT NULL,
    enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    registered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_devices_model_id (model_id),
    KEY idx_devices_enabled (enabled),
    CONSTRAINT fk_devices_model
        FOREIGN KEY (model_id) REFERENCES models(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_events (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    imei               VARCHAR(15)     NOT NULL,
    native_type        VARCHAR(100)    NOT NULL,
    feature_id         INT UNSIGNED    NULL,
    native_data        JSON            NOT NULL COMMENT 'raw/native protocol payload (string + parsed fields)',
    generalized_data   JSON            NOT NULL COMMENT 'canonical normalized payload used by product/API',
    received_at        BIGINT UNSIGNED NOT NULL COMMENT 'epoch milliseconds',
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_events_imei (imei),
    KEY idx_events_imei_received (imei, received_at DESC),
    KEY idx_events_feature_received (feature_id, received_at DESC),
    KEY idx_events_created (created_at DESC),
    CONSTRAINT fk_events_device
        FOREIGN KEY (imei) REFERENCES devices(imei)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_events_feature
        FOREIGN KEY (feature_id) REFERENCES features(id)
        ON DELETE SET NULL
        ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
