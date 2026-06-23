CREATE TABLE IF NOT EXISTS suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_id INTEGER NOT NULL REFERENCES suppliers(id),
    model TEXT NOT NULL,
    protocol TEXT NOT NULL,
    image_path TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(supplier_id, model)
);

CREATE TABLE IF NOT EXISTS model_request_capabilities (
    model_id INTEGER NOT NULL REFERENCES models(id) ON DELETE CASCADE,
    downlink_command TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (model_id, downlink_command)
);

CREATE TABLE IF NOT EXISTS whitelist (
    imei TEXT PRIMARY KEY,
    supplier TEXT NOT NULL,
    model TEXT NOT NULL,
    device_type TEXT NOT NULL DEFAULT 'watch',
    license_id TEXT NOT NULL DEFAULT '0',
    sim_number TEXT NOT NULL DEFAULT '',
    device_id TEXT NOT NULL DEFAULT '',
    source_system TEXT NOT NULL DEFAULT '',
    source_device_id TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS device_configurations (
    imei TEXT NOT NULL,
    config_key TEXT NOT NULL,
    protocol TEXT NOT NULL,
    supplier TEXT NOT NULL DEFAULT '',
    model TEXT NOT NULL DEFAULT '',
    command TEXT NOT NULL DEFAULT '',
    desired_payload TEXT NOT NULL DEFAULT '{}',
    reported_payload TEXT NOT NULL DEFAULT '{}',
    last_status TEXT NOT NULL DEFAULT '',
    last_command_id TEXT NOT NULL DEFAULT '',
    desired_updated_at TEXT NOT NULL DEFAULT '',
    reported_at TEXT NOT NULL DEFAULT '',
    applied_at TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (imei, config_key)
);

CREATE TABLE IF NOT EXISTS api_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL,
    license_id TEXT NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_device_configurations_imei ON device_configurations(imei);
CREATE INDEX IF NOT EXISTS idx_model_request_capabilities_model ON model_request_capabilities(model_id);
CREATE INDEX IF NOT EXISTS idx_api_users_role_license ON api_users(role, license_id);
