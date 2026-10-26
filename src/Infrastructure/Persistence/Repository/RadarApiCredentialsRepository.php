<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use PDO;

final class RadarApiCredentialsRepository
{
    use FetchesRows;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByLicenseRefId(int $licenseRefId): ?array
    {
        return $this->fetchOne('SELECT * FROM radar_api_credentials WHERE license_ref_id = ?', [$licenseRefId]);
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
