<?php

namespace App\Repository;

use App\Database\Repository;

class SupplierRepository extends Repository
{
    protected function table(): string
    {
        return 'suppliers';
    }

    protected function columns(): string
    {
        return 'id, name, enabled, created_at, updated_at';
    }

    protected function pk(): string
    {
        return 'id';
    }

    protected function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'enabled' => (bool)$row['enabled'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    protected function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['name'] ?? null) !== null && $filters['name'] !== '') {
            $where[] = 'name LIKE :name';
            $params['name'] = '%' . $filters['name'] . '%';
        }

        if (($filters['enabled'] ?? null) !== null) {
            $where[] = 'enabled = :enabled';
            $params['enabled'] = $filters['enabled'] ? 1 : 0;
        }

        return [$where ? (' WHERE ' . implode(' AND ', $where)) : '', $params];
    }

    protected function serialize(array $data): array
    {
        $serialized = $data;
        if (array_key_exists('enabled', $serialized)) {
            $serialized['enabled'] = $serialized['enabled'] ? 1 : 0;
        }
        return $serialized;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . $this->columns() . ' FROM ' . $this->table() . ' WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function countModelsUsingSupplier(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM models WHERE supplier_id = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }
}
