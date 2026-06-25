<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class LicenseRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?int $companyId = null): array
    {
        if ($companyId !== null) {
            $stmt = $this->pdo->prepare("SELECT l.id, l.company_id, l.license_id, l.name, l.created_at, l.updated_at, c.name AS company_name FROM licenses l LEFT JOIN companies c ON c.id = l.company_id WHERE l.company_id = ? ORDER BY l.license_id");
            $stmt->execute([$companyId]);
        } else {
            $stmt = $this->pdo->query("SELECT l.id, l.company_id, l.license_id, l.name, l.created_at, l.updated_at, c.name AS company_name FROM licenses l LEFT JOIN companies c ON c.id = l.company_id ORDER BY c.name, l.license_id");
        }

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findByLicenseId(string $licenseId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE license_id = ? ORDER BY company_id');
        $stmt->execute([$licenseId]);

        return $stmt->fetchAll();
    }

    public function findByCompanyId(int $companyId): array
    {
        $stmt = $this->pdo->prepare("SELECT l.id, l.company_id, l.license_id, l.name, l.created_at, l.updated_at, c.name AS company_name FROM licenses l LEFT JOIN companies c ON c.id = l.company_id WHERE l.company_id = ? ORDER BY l.license_id");
        $stmt->execute([$companyId]);

        return $stmt->fetchAll();
    }

    public function create(int $companyId, string $licenseId, string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM licenses WHERE company_id = ? AND license_id = ?');
        $stmt->execute([$companyId, $licenseId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int)$existing;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT INTO licenses (company_id, license_id, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $licenseId, $name, $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, int $companyId, string $licenseId, string $name): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE licenses SET company_id = ?, license_id = ?, name = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$companyId, $licenseId, $name, $now, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM licenses WHERE id = ?');
        $stmt->execute([$id]);
    }
}
