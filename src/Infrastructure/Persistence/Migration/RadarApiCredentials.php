<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * As credenciais da cloud dos radares passam a viver na base, uma linha por licença.
 *
 * O acesso ao fornecedor é do inquilino e não do processo: com a conta de uma licença, os
 * radares das outras respondem que estão offline mesmo a publicar, e nem o endereço base
 * coincide entre elas.
 */
final class RadarApiCredentials implements Migration
{
    public function version(): string
    {
        return '2026_09_17_radar_api_credentials';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS radar_api_credentials (
                license_ref_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                base_url VARCHAR(255) NOT NULL,
                username VARCHAR(96) NOT NULL,
                password VARCHAR(255) NOT NULL,
                app_id VARCHAR(96) NOT NULL,
                app_secret VARCHAR(255) NOT NULL,
                access_token VARCHAR(512) NOT NULL DEFAULT '',
                refresh_token VARCHAR(512) NOT NULL DEFAULT '',
                token_expires_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_radar_api_credentials_license FOREIGN KEY (license_ref_id)
                    REFERENCES licenses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
