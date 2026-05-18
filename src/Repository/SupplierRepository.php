<?php

namespace App\Repository;

class SupplierRepository
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, enabled, created_at, updated_at FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function list(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = ($page - 1) * $limit;

        $stmt = $this->pdo->prepare(
            'SELECT id, name, enabled, created_at, updated_at
             FROM suppliers'
            . $where
            . ' ORDER BY id ASC LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM suppliers' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $serialized = $this->serialize($data);
        $cols = implode(', ', array_keys($serialized));
        $placeholders = ':' . implode(', :', array_keys($serialized));

        $stmt = $this->pdo->prepare(
            'INSERT INTO suppliers (' . $cols . ') VALUES (' . $placeholders . ')'
        );
        $stmt->execute($serialized);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $serialized = $this->serialize($data);
        $sets = [];
        foreach ($serialized as $key => $value) {
            $sets[] = "$key = :$key";
        }

        $stmt = $this->pdo->prepare(
            'UPDATE suppliers SET ' . implode(', ', $sets) . ' WHERE id = :pk'
        );
        $stmt->execute(['pk' => $id] + $serialized);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, enabled, created_at, updated_at FROM suppliers WHERE name = ?');
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

    private function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'enabled' => (bool)$row['enabled'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function buildWhere(array $filters): array
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

    private function serialize(array $data): array
    {
        $serialized = $data;
        if (array_key_exists('enabled', $serialized)) {
            $serialized['enabled'] = $serialized['enabled'] ? 1 : 0;
        }
        return $serialized;
    }
}
