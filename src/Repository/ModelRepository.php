<?php

namespace App\Repository;

use App\Database\Repository;

class ModelRepository extends Repository
{
    private const TABLE = 'models m JOIN suppliers s ON s.id = m.supplier_id';
    private const COLS = 'm.id, m.supplier_id, s.name AS supplier_name, m.code, m.name, m.protocol, m.transport, m.source_doc, m.enabled, m.passive, m.active, m.features, m.created_at, m.updated_at';

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function columns(): string
    {
        return self::COLS;
    }

    protected function pk(): string
    {
        return 'm.code';
    }

    protected function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'supplier_id' => (int)$row['supplier_id'],
            'supplier_name' => $row['supplier_name'],
            'code' => $row['code'],
            'name' => $row['name'],
            'protocol' => $row['protocol'],
            'transport' => $row['transport'],
            'source_doc' => $row['source_doc'],
            'enabled' => (bool)$row['enabled'],
            'passive' => $this->decodeJsonArray($row['passive']),
            'active' => $this->decodeJsonArray($row['active']),
            'features' => $this->decodeJsonObject($row['features']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    protected function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['code'] ?? null) !== null && $filters['code'] !== '') {
            $where[] = 'm.code LIKE :code';
            $params['code'] = '%' . $filters['code'] . '%';
        }
        if (($filters['name'] ?? null) !== null && $filters['name'] !== '') {
            $where[] = 'm.name LIKE :name';
            $params['name'] = '%' . $filters['name'] . '%';
        }
        if (($filters['supplierId'] ?? null) !== null) {
            $where[] = 'm.supplier_id = :supplier_id';
            $params['supplier_id'] = (int)$filters['supplierId'];
        }
        if (($filters['supplierName'] ?? null) !== null && $filters['supplierName'] !== '') {
            $where[] = 's.name = :supplier_name';
            $params['supplier_name'] = $filters['supplierName'];
        }
        if (($filters['protocol'] ?? null) !== null && $filters['protocol'] !== '') {
            $where[] = 'm.protocol = :protocol';
            $params['protocol'] = $filters['protocol'];
        }
        if (($filters['transport'] ?? null) !== null && $filters['transport'] !== '') {
            $where[] = 'm.transport = :transport';
            $params['transport'] = $filters['transport'];
        }
        if (($filters['enabled'] ?? null) !== null) {
            $where[] = 'm.enabled = :enabled';
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
        if (array_key_exists('passive', $serialized) && is_array($serialized['passive'])) {
            $serialized['passive'] = json_encode(array_values($serialized['passive']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('active', $serialized) && is_array($serialized['active'])) {
            $serialized['active'] = json_encode(array_values($serialized['active']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('features', $serialized) && is_array($serialized['features'])) {
            $serialized['features'] = json_encode($serialized['features'], JSON_UNESCAPED_UNICODE);
        }

        return $serialized;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->columns() . '
             FROM ' . $this->table() . '
             WHERE m.code = ?'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function existsCode(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM models WHERE code = ?');
        $stmt->execute([$code]);
        return (bool)$stmt->fetchColumn();
    }

    public function updateByCode(string $code, array $data): void
    {
        if ($data === []) {
            return;
        }

        $serialized = $this->serialize($data);
        $sets = [];
        $params = ['code_filter' => $code];
        foreach ($serialized as $key => $value) {
            $sets[] = "m.$key = :$key";
            $params[$key] = $value;
        }

        $stmt = $this->pdo->prepare('UPDATE models m SET ' . implode(', ', $sets) . ' WHERE m.code = :code_filter');
        $stmt->execute($params);
    }

    public function deleteByCode(string $code): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM models WHERE code = ?');
        $stmt->execute([$code]);
    }

    public function countDevicesUsingModelCode(string $code): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM devices d
             JOIN models m ON m.id = d.model_id
             WHERE m.code = ?'
        );
        $stmt->execute([$code]);
        return (int)$stmt->fetchColumn();
    }

    public function allProfiles(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ' . $this->columns() . '
             FROM ' . $this->table()
        );

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function list(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = ($page - 1) * $limit;

        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->columns() . '
             FROM ' . $this->table()
            . $where
            . ' ORDER BY m.code ASC LIMIT :limit OFFSET :offset'
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
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . $where
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO models
                (supplier_id, code, name, protocol, transport, source_doc, enabled, passive, active, features)
             VALUES
                (:supplier_id, :code, :name, :protocol, :transport, :source_doc, :enabled, :passive, :active, :features)'
        );

        $stmt->execute($this->serialize($data));
        return (int)$this->pdo->lastInsertId();
    }
}
