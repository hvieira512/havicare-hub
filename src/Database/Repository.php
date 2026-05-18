<?php

namespace App\Database;

abstract class Repository
{
    protected \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    abstract protected function table(): string;

    abstract protected function columns(): string;

    abstract protected function pk(): string;

    abstract protected function hydrate(array $row): array;

    protected function serialize(array $data): array
    {
        return $data;
    }

    public function find(string|int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->columns() . ' FROM ' . $this->table() . ' WHERE ' . $this->pk() . ' = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function list(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = ($page - 1) * $limit;

        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->columns() . ' FROM ' . $this->table()
            . $where
            . ' ORDER BY ' . $this->pk() . ' ASC LIMIT :limit OFFSET :offset'
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
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->table() . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $serialized = $this->serialize($data);
        $cols = implode(', ', array_keys($serialized));
        $placeholders = ':' . implode(', :', array_keys($serialized));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->table() . ' (' . $cols . ') VALUES (' . $placeholders . ')'
        );
        $stmt->execute($serialized);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(string|int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $serialized = $this->serialize($data);
        $sets = [];
        foreach ($serialized as $key => $value) {
            $sets[] = "$key = :$key";
        }
        $serialized[$this->pk()] = $id;

        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->table() . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . $this->pk() . ' = :pk'
        );
        $stmt->execute(['pk' => $id] + $serialized);
    }

    public function delete(string|int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->table() . ' WHERE ' . $this->pk() . ' = ?');
        $stmt->execute([$id]);
    }

    protected function buildWhere(array $filters): array
    {
        return ['', []];
    }

    protected function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    protected function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
