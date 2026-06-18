<?php

namespace App\Repositories;

use PDO;

final class DashboardModelRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo
            ->query('SELECT m.id, m.supplier_id, s.name AS supplier, m.model, m.protocol, m.image_path AS "image" FROM models m JOIN suppliers s ON s.id = m.supplier_id ORDER BY s.name, m.model')
            ->fetchAll();
    }

    public function find(string $supplier, string $model): ?array
    {
        $stmt = $this->pdo->prepare('SELECT m.*, s.name AS supplier_name FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = ? AND m.model = ?');
        $stmt->execute([$supplier, $model]);

        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT m.*, s.name AS supplier_name FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE m.id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function protocolForModel(string $supplier, string $model): string
    {
        $entry = $this->find($supplier, $model);

        return (string)($entry['protocol'] ?? '');
    }

    public function add(int $supplierId, string $model, string $protocol, ?string $imagePath = null): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $existing = $this->findBySupplierId($supplierId, $model);
        $storedImagePath = $imagePath ?? (string)($existing['image_path'] ?? '');
        $stmt = $this->pdo->prepare('
            INSERT INTO models (supplier_id, model, protocol, image_path, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(supplier_id, model) DO UPDATE SET
                protocol = excluded.protocol,
                image_path = excluded.image_path,
                updated_at = ?
        ');
        $stmt->execute([$supplierId, $model, $protocol, $storedImagePath, $now, $now, $now]);
    }

    public function update(int $id, int $supplierId, string $model, string $protocol, ?string $imagePath = null): bool
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return false;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $storedImagePath = $imagePath ?? (string)($existing['image_path'] ?? '');
        $stmt = $this->pdo->prepare('UPDATE models SET supplier_id = ?, model = ?, protocol = ?, image_path = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$supplierId, $model, $protocol, $storedImagePath, $now, $id]);

        return $stmt->rowCount() > 0;
    }

    public function existsForDifferentId(int $id, int $supplierId, string $model): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM models WHERE id != ? AND supplier_id = ? AND model = ?');
        $stmt->execute([$id, $supplierId, $model]);

        return (int)$stmt->fetchColumn() > 0;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM models WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function findBySupplierId(int $supplierId, string $model): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM models WHERE supplier_id = ? AND model = ?');
        $stmt->execute([$supplierId, $model]);

        return $stmt->fetch() ?: null;
    }
}
