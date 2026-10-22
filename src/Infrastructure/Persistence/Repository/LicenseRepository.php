<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use PDO;

final class LicenseRepository
{
    use FetchesRows;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * As credenciais da cloud dos radares entram como bandeira e não como linha: a listagem
     * diz que a licença já a tem, e os segredos nunca saem da tabela deles.
     */
    private const COLUMNS = 'l.id, l.company_id, l.license_id, l.name, l.created_at, l.updated_at, c.name AS company_name,'
        . ' EXISTS (SELECT 1 FROM radar_api_credentials r WHERE r.license_ref_id = l.id) AS radar_cloud_configured,'
        // Um aparelho aponta para a licença pelo par número + empresa, e não pela chave da
        // linha: o mesmo número existe em empresas diferentes.
        . ' (SELECT COUNT(*) FROM whitelist w WHERE w.license_id = l.license_id AND w.company = c.name) AS device_count';

    /** @return list<array<string, mixed>> */
    public function all(?int $companyId = null): array
    {
        $from = 'SELECT ' . self::COLUMNS . ' FROM licenses l LEFT JOIN companies c ON c.id = l.company_id';

        return $companyId !== null
            ? $this->fetchRows($from . ' WHERE l.company_id = ? ORDER BY l.license_id', [$companyId])
            : $this->fetchRows($from . ' ORDER BY c.name, l.license_id');
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM licenses WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function findByLicenseId(int $licenseId): array
    {
        return $this->fetchRows('SELECT * FROM licenses WHERE license_id = ? ORDER BY company_id', [$licenseId]);
    }

    /** @return array<string, mixed>|null */
    public function findByCompanyAndLicense(int $companyId, int $licenseId): ?array
    {
        return $this->fetchOne('SELECT * FROM licenses WHERE company_id = ? AND license_id = ?', [$companyId, $licenseId]);
    }

    public function create(int $companyId, int $licenseId, string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM licenses WHERE company_id = ? AND license_id = ?');
        $stmt->execute([$companyId, $licenseId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int)$existing;
        }

        $stmt = $this->pdo->prepare('INSERT INTO licenses (company_id, license_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$companyId, $licenseId, $name]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, int $companyId, int $licenseId, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE licenses SET company_id = ?, license_id = ?, name = ? WHERE id = ?');
        $stmt->execute([$companyId, $licenseId, $name, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM licenses WHERE id = ?');
        $stmt->execute([$id]);
    }
}
