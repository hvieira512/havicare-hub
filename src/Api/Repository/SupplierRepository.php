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
            ->query("SELECT id, name, created_at, updated_at, (SELECT COUNT(*) FROM models WHERE supplier_id = suppliers.id) AS model_count FROM suppliers ORDER BY name")
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

    // Quem der rota a isto tem de limpar o memo do `ModelRepository`: o `all()` junta `s.name`.
    public function create(string $name): int
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return (int)($existing['id'] ?? 0);
        }

        $stmt = $this->pdo->prepare('INSERT INTO suppliers (name) VALUES (?)');
        $stmt->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
    }

}
