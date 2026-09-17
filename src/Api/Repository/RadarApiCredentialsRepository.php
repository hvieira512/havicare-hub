<?php

namespace Hub\Api\Repository;

use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class RadarApiCredentialsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

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

    /**
     * Guardar credenciais não mexe no token: o que estiver lá deixa de servir de qualquer
     * maneira, e é o próximo pedido ao fornecedor que trata de o renovar.
     */
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

    /**
     * O caminho da sincronização, separado do do formulário: a renovação do token acontece a
     * qualquer momento e não pode escrever por cima do que alguém esteja a editar.
     */
    public function storeToken(
        int $licenseRefId,
        string $accessToken,
        string $refreshToken,
        ?string $expiresAt,
    ): void {
        $stmt = $this->pdo->prepare('
            UPDATE radar_api_credentials
            SET access_token = ?, refresh_token = ?, token_expires_at = ?
            WHERE license_ref_id = ?
        ');
        $stmt->execute([$accessToken, $refreshToken, $expiresAt, $licenseRefId]);
    }

    public function delete(int $licenseRefId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM radar_api_credentials WHERE license_ref_id = ?');
        $stmt->execute([$licenseRefId]);
    }
}
