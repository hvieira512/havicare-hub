<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class SupplierRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo
            ->query("SELECT id, name, device_type, enabled, created_at, updated_at, (SELECT COUNT(*) FROM models WHERE supplier_id = suppliers.id) AS model_count FROM suppliers ORDER BY name")
            ->fetchAll();
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE name = ?');
        $stmt->execute([$name]);

        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(string $name, string $deviceType = 'watch', bool $enabled = true): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO suppliers (name, device_type, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $deviceType, $enabled ? 1 : 0, $now, $now]);

        $stmt = $this->pdo->prepare('SELECT id FROM suppliers WHERE name = ?');
        $stmt->execute([$name]);

        return (int)$stmt->fetchColumn();
    }

    public function updateDeviceType(int $id, string $deviceType): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE suppliers SET device_type = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$deviceType, $now, $id]);
    }

    public function findByDeviceType(string $deviceType): array
    {
        $stmt = $this->pdo->prepare("SELECT id, name, device_type, enabled, created_at, updated_at, (SELECT COUNT(*) FROM models WHERE supplier_id = suppliers.id) AS model_count FROM suppliers WHERE device_type = ? ORDER BY name");
        $stmt->execute([$deviceType]);

        return $stmt->fetchAll();
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE suppliers SET enabled = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, $now, $id]);
    }

    public function rename(int $id, string $newName): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $old = $this->findById($id);
        if ($old === null) {
            return;
        }

        $oldName = (string)($old['name'] ?? '');
        $this->pdo->beginTransaction();
        $this->pdo->prepare('UPDATE suppliers SET name = ?, updated_at = ? WHERE id = ?')->execute([$newName, $now, $id]);
        $this->pdo->prepare('UPDATE whitelist SET supplier = ?, updated_at = ? WHERE supplier = ?')->execute([$newName, $now, $oldName]);
        $this->pdo->commit();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countModels(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM models WHERE supplier_id = ?');
        $stmt->execute([$id]);

        return (int)$stmt->fetchColumn();
    }
}
