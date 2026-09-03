CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(96) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Os tipos de dispositivo, num sítio só.
--
-- Eram um `ENUM` repetido em três tabelas, e acrescentar um tipo eram três `ALTER TABLE` que
-- tinham de concordar -- a discordância não dava erro, dava um dispositivo registado e sem
-- capacidade nenhuma. O conteúdo vem do `config/device-types.json`, que o `DeviceTypeCatalog`
-- serve e que o frontend também lê; esta tabela existe para as chaves estrangeiras terem
-- destino, e o semeador mantém-na igual ao ficheiro.
CREATE TABLE IF NOT EXISTS device_types (
    device_type VARCHAR(32) NOT NULL PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(96) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS models (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id BIGINT UNSIGNED NOT NULL,
    internal_model VARCHAR(96) NOT NULL,
    commercial_name VARCHAR(96) NOT NULL,
    device_type VARCHAR(32) NOT NULL DEFAULT 'watch',
    image_path VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_models_supplier_internal_model (supplier_id, internal_model),
    -- O índice existe para a chave estrangeira ter onde assentar; sem ele o MariaDB cria um
    -- com o nome da restrição.
    KEY idx_models_device_type (device_type),
    CONSTRAINT fk_models_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_models_device_type FOREIGN KEY (device_type) REFERENCES device_types(device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Não há tabela de pares fornecedor x tipo de dispositivo: a pergunta responde-se com um
-- `SELECT DISTINCT` sobre a `models`, que é a origem. Ver `ModelRepository::supplierDeviceTypes()`.

CREATE TABLE IF NOT EXISTS capabilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_type VARCHAR(32) NOT NULL DEFAULT 'watch',
    section ENUM('telemetry', 'health', 'contacts', 'alarms', 'settings_system') NOT NULL,
    capability_key VARCHAR(64) NOT NULL,
    label VARCHAR(96) NOT NULL,
    -- Sem `is_telemetry`: era `section = 'telemetry'` escrito outra vez, e é assim que a
    -- consulta o calcula. As outras duas bandeiras não são redutíveis à secção.
    is_configurable TINYINT(1) NOT NULL DEFAULT 0,
    is_requestable TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- A chave única começa pelo `device_type` e serve a chave estrangeira sem índice extra.
    UNIQUE KEY uq_capabilities_device_type_key (device_type, capability_key),
    KEY idx_capabilities_device_type_section_label (device_type, section, label),
    CONSTRAINT fk_capabilities_device_type FOREIGN KEY (device_type) REFERENCES device_types(device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A ligação modelo x capacidade, pelo par natural.
--
-- Referenciava o `capabilities.id`, que o código nunca fala: todas as leituras juntavam a
-- `capabilities` só para traduzir o id de volta à chave, e todas as escritas faziam a
-- tradução inversa antes. Renomear uma capacidade obrigava a preservar o `id` à mão, ou as
-- ligações desapareciam por cascata -- agora é o `ON UPDATE CASCADE` que as leva.
--
-- O `capabilities.id` fica onde estava: é o identificador que a API expõe.
CREATE TABLE IF NOT EXISTS model_capabilities (
    model_id BIGINT UNSIGNED NOT NULL,
    device_type VARCHAR(32) NOT NULL,
    capability_key VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_requestable TINYINT(1) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (model_id, device_type, capability_key),
    -- A chave primária não começa pelo par, e a chave estrangeira precisa de um índice que
    -- comece.
    KEY idx_model_capabilities_capability (device_type, capability_key),
    CONSTRAINT fk_model_capabilities_model_v2 FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    CONSTRAINT fk_model_capabilities_capability_v3 FOREIGN KEY (device_type, capability_key)
        REFERENCES capabilities(device_type, capability_key) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whitelist (
    imei VARCHAR(64) NOT NULL PRIMARY KEY,
    supplier VARCHAR(96) NOT NULL,
    model VARCHAR(96) NOT NULL,
    device_type VARCHAR(32) NOT NULL DEFAULT 'watch',
    license_id INT UNSIGNED NULL DEFAULT NULL,
    sim_number VARCHAR(64) NOT NULL DEFAULT '',
    device_id VARCHAR(96) NOT NULL DEFAULT '',
    company VARCHAR(96) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_whitelist_supplier_model (supplier, model),
    -- A licença à cabeça: o âmbito do inquilino filtra por ela sozinha, e com o
    -- `device_type` à frente o optimizador não conseguia procurar.
    KEY idx_whitelist_license_device_type (license_id, device_type),
    KEY idx_whitelist_company (company),
    KEY idx_whitelist_device_id (device_id),
    -- Nenhum dos índices acima começa pelo `device_type`, e a chave estrangeira precisa de um.
    KEY idx_whitelist_device_type (device_type),
    CONSTRAINT fk_whitelist_device_type FOREIGN KEY (device_type) REFERENCES device_types(device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gateway_device_links (
    gateway_device_key VARCHAR(64) NOT NULL,
    linked_device_key VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (gateway_device_key, linked_device_key),
    KEY idx_gateway_device_links_linked (linked_device_key, enabled),
    CONSTRAINT fk_gateway_device_links_gateway FOREIGN KEY (gateway_device_key) REFERENCES whitelist(imei) ON DELETE CASCADE,
    CONSTRAINT fk_gateway_device_links_device FOREIGN KEY (linked_device_key) REFERENCES whitelist(imei) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_configurations (
    imei VARCHAR(64) NOT NULL,
    config_key VARCHAR(64) NOT NULL,
    native_key VARCHAR(64) NOT NULL,
    protocol VARCHAR(64) NOT NULL,
    -- Sem fornecedor nem modelo: o dono do IMEI é a `whitelist`. A cópia que aqui existia
    -- chegou a declarar dois modelos para o mesmo aparelho.
    command VARCHAR(64) NOT NULL DEFAULT '',
    desired_payload LONGTEXT NOT NULL,
    reported_payload LONGTEXT NOT NULL,
    -- Sem `confirmed_revision`: quem responde "este dispositivo está atrasado?" é o
    -- `sync_status` da alteração, e a coluna era escrita e nunca lida.
    desired_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    current_change_id VARCHAR(64) NOT NULL DEFAULT '',
    confirmation_mode VARCHAR(32) NOT NULL DEFAULT 'execution_ack',
    last_status VARCHAR(32) NOT NULL DEFAULT '',
    last_error VARCHAR(64) NOT NULL DEFAULT '',
    last_command_id VARCHAR(64) NOT NULL DEFAULT '',
    -- Instantes em `DATETIME NULL`: a ausência é `NULL` e não a cadeia vazia, que era o
    -- segundo vocabulário para "ainda não" neste esquema. A API continua a falar ISO.
    desired_updated_at DATETIME NULL DEFAULT NULL,
    reported_at DATETIME NULL DEFAULT NULL,
    applied_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (imei, config_key, native_key),
    -- Sem o `imei` à frente: a confirmação procura pelo `current_change_id` sozinho.
    KEY idx_device_config_change (current_change_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_configuration_changes (
    change_id VARCHAR(64) NOT NULL PRIMARY KEY,
    imei VARCHAR(64) NOT NULL,
    config_key VARCHAR(64) NOT NULL,
    desired_revision BIGINT UNSIGNED NOT NULL,
    desired_payload LONGTEXT NOT NULL,
    effective_payload LONGTEXT NULL,
    sync_status VARCHAR(32) NOT NULL DEFAULT 'pending_delivery',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL DEFAULT NULL,
    -- `NULL` é "esta é a alteração corrente", e é o que a condição procura.
    superseded_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_configuration_change_revision (imei, config_key, desired_revision),
    KEY idx_configuration_change_current (imei, config_key, superseded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_configuration_operations (
    operation_id VARCHAR(64) NOT NULL PRIMARY KEY,
    change_id VARCHAR(64) NOT NULL,
    -- Sem `imei` nem `config_key`: são da alteração, e a chave estrangeira dá o caminho.
    native_key VARCHAR(64) NOT NULL,
    native_type VARCHAR(64) NOT NULL,
    protocol VARCHAR(64) NOT NULL,
    confirmation_mode VARCHAR(32) NOT NULL DEFAULT 'execution_ack',
    delivery_status VARCHAR(32) NOT NULL DEFAULT 'created',
    error_code VARCHAR(64) NOT NULL DEFAULT '',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    sent_at DATETIME NULL DEFAULT NULL,
    acknowledged_at DATETIME NULL DEFAULT NULL,
    sequence_number INT UNSIGNED NOT NULL DEFAULT 0,
    -- A única leitura da tabela: as operações de uma alteração, por ordem. Não há
    -- despachante a consultá-la por estado -- a entrega vive na fila do Redis.
    KEY idx_configuration_operation_change (change_id, sequence_number),
    CONSTRAINT fk_configuration_operation_change FOREIGN KEY (change_id)
        REFERENCES device_configuration_changes(change_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(96) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    license_id INT UNSIGNED NOT NULL,
    name VARCHAR(96) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- A chave única começa pelo `company_id` e satisfaz a chave estrangeira; um índice só
    -- com essa coluna era o seu prefixo exacto.
    UNIQUE KEY uq_licenses_company_license (company_id, license_id),
    CONSTRAINT fk_licenses_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(96) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('hub_admin', 'license_client') NOT NULL,
    -- A licença é só a referência. Um `hub_admin` não tem: NULL. O número da licença sai da
    -- linha apontada, e não de uma cópia que se pudesse desencontrar dela -- é ele que decide
    -- o âmbito do inquilino, onde NULL não é "desconhecido" mas "sem filtro".
    license_ref_id BIGINT UNSIGNED NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_api_users_license_ref (license_ref_id),
    CONSTRAINT fk_api_users_license_ref FOREIGN KEY (license_ref_id) REFERENCES licenses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(64) NOT NULL,
    imei VARCHAR(64) NOT NULL,
    protocol VARCHAR(64) NOT NULL DEFAULT '',
    model VARCHAR(96) NOT NULL DEFAULT '',
    ident VARCHAR(96) NOT NULL DEFAULT '',
    -- O dono, quando o protocolo o diz: o tópico do radar traz a licença, um MAC não traz
    -- nada. As duas juntas ou nenhuma.
    license_id INT UNSIGNED NULL DEFAULT NULL,
    company VARCHAR(96) NULL DEFAULT NULL,
    reason VARCHAR(96) NOT NULL DEFAULT '',
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_dashboard_notifications_identity (type, imei, protocol),
    -- Só o `read_at`: a contagem de não lidas não ordena, e a listagem ordenada usa o
    -- índice ao lado.
    KEY idx_dashboard_notifications_unread (read_at),
    KEY idx_dashboard_notifications_latest (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS private_radio_map_access_points (
    bssid_hash CHAR(64) NOT NULL PRIMARY KEY,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    accuracy_meters DECIMAL(8,2) NOT NULL,
    observation_count INT UNSIGNED NOT NULL DEFAULT 1,
    source ENUM('learned', 'manual') NOT NULL DEFAULT 'learned',
    conflicted TINYINT(1) NOT NULL DEFAULT 0,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    -- Sem índice secundário: a única consulta é `WHERE bssid_hash IN (…)`, que usa a chave
    -- primária, e o `conflicted` é filtrado em PHP.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
