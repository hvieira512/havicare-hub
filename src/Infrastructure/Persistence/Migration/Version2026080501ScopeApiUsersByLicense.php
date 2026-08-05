<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026080501ScopeApiUsersByLicense implements Migration
{
    public function version(): string
    {
        return '2026080501_scope_api_users_by_license';
    }

    public function up(PDO $pdo): void
    {
        $schema = new MysqlSchema($pdo);
        $schema->addColumn('api_users', 'license_ref_id', 'BIGINT UNSIGNED NULL AFTER license_id');
        $schema->addIndex('api_users', 'idx_api_users_license_ref', 'license_ref_id');

        $pdo->exec("
            UPDATE api_users u
            JOIN (
                SELECT license_id, MIN(id) AS license_ref_id
                FROM licenses
                GROUP BY license_id
                HAVING COUNT(*) = 1
            ) unique_license ON unique_license.license_id = u.license_id
            SET u.license_ref_id = unique_license.license_ref_id
            WHERE u.role = 'license_client' AND u.license_ref_id IS NULL
        ");

        if (!$schema->hasForeignKey('api_users', 'fk_api_users_license_ref')) {
            $pdo->exec('
                ALTER TABLE api_users
                ADD CONSTRAINT fk_api_users_license_ref
                FOREIGN KEY (license_ref_id) REFERENCES licenses(id) ON DELETE SET NULL
            ');
        }
    }
}
