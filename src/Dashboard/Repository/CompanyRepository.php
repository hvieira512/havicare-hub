<?php

namespace Hub\Dashboard\Repository;

use Hub\Dashboard\TimestampFormatter;
use PDO;

final class CompanyRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return TimestampFormatter::normalizeRows($this->pdo
            ->query("SELECT c.id, c.name, c.created_at, c.updated_at, (SELECT COUNT(*) FROM licenses WHERE company_id = c.id) AS license_count FROM companies c ORDER BY c.name")
            ->fetchAll());
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM companies WHERE name = ?');
        $stmt->execute([$name]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    public function create(string $name): int
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return (int)($existing['id'] ?? 0);
        }

        $stmt = $this->pdo->prepare('INSERT INTO companies (name) VALUES (?)');
        $stmt->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE companies SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM licenses WHERE company_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$id]);
    }
}
