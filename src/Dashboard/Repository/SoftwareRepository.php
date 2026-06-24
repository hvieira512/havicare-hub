<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class SoftwareRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo
            ->query("SELECT s.id, s.name, s.created_at, s.updated_at, (SELECT COUNT(*) FROM licenses WHERE software_id = s.id) AS license_count FROM software s ORDER BY s.name")
            ->fetchAll();
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM software WHERE name = ?');
        $stmt->execute([$name]);

        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM software WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(string $name): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO software (name, created_at, updated_at) VALUES (?, ?, ?)');
        $stmt->execute([$name, $now, $now]);

        $stmt = $this->pdo->prepare('SELECT id FROM software WHERE name = ?');
        $stmt->execute([$name]);

        return (int)$stmt->fetchColumn();
    }

    public function update(int $id, string $name): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE software SET name = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$name, $now, $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM licenses WHERE software_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM software WHERE id = ?')->execute([$id]);
    }
}
