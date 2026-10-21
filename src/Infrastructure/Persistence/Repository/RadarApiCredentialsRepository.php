<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class RadarApiCredentialsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByLicenseRefId(int $licenseRefId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM radar_api_credentials WHERE license_ref_id = ?');
        $stmt->execute([$licenseRefId]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    /**
     * As credenciais pelo número da licença, que é o que a whitelist guarda num dispositivo --
     * e não a referência à linha da licença, que é a chave desta tabela.
     *
     * @return array<string, mixed>|null
     */
    public function findByLicenseId(int $licenseId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT c.* FROM radar_api_credentials c
            JOIN licenses l ON l.id = c.license_ref_id
            WHERE l.license_id = ?
            LIMIT 1
        ');
        $stmt->execute([$licenseId]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    public function upsert(
        int $licenseRefId,
        string $baseUrl,
        string $username,
        string $password,
        string $appId,
        string $appSecret,
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO radar_api_credentials (license_ref_id, base_url, username, password, app_id, app_secret)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                base_url = VALUES(base_url),
                username = VALUES(username),
                password = VALUES(password),
                app_id = VALUES(app_id),
                app_secret = VALUES(app_secret)
        ');
        $stmt->execute([$licenseRefId, $baseUrl, $username, $password, $appId, $appSecret]);
    }

    public function delete(int $licenseRefId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM radar_api_credentials WHERE license_ref_id = ?');
        $stmt->execute([$licenseRefId]);
    }
}
