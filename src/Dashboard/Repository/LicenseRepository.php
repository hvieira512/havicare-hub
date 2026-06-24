<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class LicenseRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?int $softwareId = null): array
    {
        if ($softwareId !== null) {
            $stmt = $this->pdo->prepare("SELECT l.id, l.software_id, l.license_id, l.name, l.created_at, l.updated_at, s.name AS software_name FROM licenses l LEFT JOIN software s ON s.id = l.software_id WHERE l.software_id = ? ORDER BY l.license_id");
            $stmt->execute([$softwareId]);
        } else {
            $stmt = $this->pdo->query("SELECT l.id, l.software_id, l.license_id, l.name, l.created_at, l.updated_at, s.name AS software_name FROM licenses l LEFT JOIN software s ON s.id = l.software_id ORDER BY s.name, l.license_id");
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
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE license_id = ? ORDER BY software_id');
        $stmt->execute([$licenseId]);

        return $stmt->fetchAll();
    }

    public function findBySoftwareId(int $softwareId): array
    {
        $stmt = $this->pdo->prepare("SELECT l.id, l.software_id, l.license_id, l.name, l.created_at, l.updated_at, s.name AS software_name FROM licenses l LEFT JOIN software s ON s.id = l.software_id WHERE l.software_id = ? ORDER BY l.license_id");
        $stmt->execute([$softwareId]);

        return $stmt->fetchAll();
    }

    public function create(int $softwareId, string $licenseId, string $name): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO licenses (software_id, license_id, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$softwareId, $licenseId, $name, $now, $now]);

        $stmt = $this->pdo->prepare('SELECT id FROM licenses WHERE software_id = ? AND license_id = ?');
        $stmt->execute([$softwareId, $licenseId]);

        return (int)$stmt->fetchColumn();
    }

    public function update(int $id, int $softwareId, string $licenseId, string $name): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE licenses SET software_id = ?, license_id = ?, name = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$softwareId, $licenseId, $name, $now, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM licenses WHERE id = ?');
        $stmt->execute([$id]);
    }
}
