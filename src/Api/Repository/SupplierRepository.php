<?php

namespace Hub\Api\Repository;

use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class SupplierRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return TimestampFormatter::normalizeRows($this->pdo
            ->query("SELECT id, name, enabled, created_at, updated_at, (SELECT COUNT(*) FROM models WHERE supplier_id = suppliers.id) AS model_count FROM suppliers ORDER BY name")
            ->fetchAll());
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE name = ?');
        $stmt->execute([$name]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    public function create(string $name, bool $enabled = true): int
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return (int)($existing['id'] ?? 0);
        }

        $stmt = $this->pdo->prepare('INSERT INTO suppliers (name, enabled) VALUES (?, ?)');
        $stmt->execute([$name, $enabled ? 1 : 0]);
        return (int)$this->pdo->lastInsertId();
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE suppliers SET enabled = ? WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, $id]);
    }

    public function rename(int $id, string $newName): void
    {
        $old = $this->findById($id);
        if ($old === null) {
            return;
        }

        $oldName = (string)($old['name'] ?? '');
        $this->pdo->beginTransaction();
        $this->pdo->prepare('UPDATE suppliers SET name = ? WHERE id = ?')->execute([$newName, $id]);
        $this->pdo->prepare('UPDATE whitelist SET supplier = ? WHERE supplier = ?')->execute([$newName, $oldName]);
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
