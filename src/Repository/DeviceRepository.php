<?php

namespace App\Repository;

class DeviceRepository
{
    private const TABLE = 'devices d JOIN models m ON m.id = d.model_id JOIN suppliers s ON s.id = m.supplier_id';
    private const COLS = 'd.imei, d.model_id, d.enabled, d.registered_at, d.updated_at, m.code AS model_code, m.name AS model_name, m.protocol, m.transport, m.enabled AS model_enabled, s.id AS supplier_id, s.name AS supplier_name';

    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ' . self::COLS . '
             FROM ' . self::TABLE . '
             ORDER BY d.registered_at ASC'
        );

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function list(array $filters, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);
        $offset = ($page - 1) * $limit;

        [$whereSql, $params] = $this->buildFilterWhere($filters);
        $sql = 'SELECT ' . self::COLS . '
                FROM ' . self::TABLE
            . $whereSql . '
                ORDER BY d.registered_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function countFiltered(array $filters): int
    {
        [$whereSql, $params] = $this->buildFilterWhere($filters);
        $sql = 'SELECT COUNT(*) FROM ' . self::TABLE . $whereSql;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $modelId = $this->resolveModelId($data);

        $stmt = $this->pdo->prepare(
            'INSERT INTO devices (imei, model_id, enabled, registered_at)
             VALUES (:imei, :model_id, :enabled, :registered_at)
             ON DUPLICATE KEY UPDATE
                model_id = VALUES(model_id),
                enabled = VALUES(enabled),
                updated_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            'imei' => $data['imei'],
            'model_id' => $modelId,
            'enabled' => isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1,
            'registered_at' => $this->toMysqlDatetime($data['registered_at'] ?? 'now'),
        ]);

        return 0;
    }

    public function delete(string $imei): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM devices WHERE imei = ?');
        $stmt->execute([$imei]);
    }

    public function toggle(string $imei, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE devices SET enabled = ? WHERE imei = ?');
        $stmt->execute([$enabled ? 1 : 0, $imei]);
    }

    public function exists(string $imei): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM devices WHERE imei = ?');
        $stmt->execute([$imei]);
        return (bool)$stmt->fetchColumn();
    }

    public function ensureExists(string $imei, string $modelCode = 'unknown', bool $enabled = true): void
    {
        if (!$this->exists($imei)) {
            $this->insert([
                'imei' => $imei,
                'model' => $modelCode,
                'enabled' => $enabled,
                'registered_at' => 'now',
            ]);
        }
    }

    private function hydrate(array $row): array
    {
        return [
            'imei' => $row['imei'],
            'model_id' => (int)$row['model_id'],
            'model_code' => $row['model_code'],
            'model_name' => $row['model_name'],
            'protocol' => $row['protocol'],
            'transport' => $row['transport'],
            'model_enabled' => (bool)$row['model_enabled'],
            'supplier_id' => (int)$row['supplier_id'],
            'supplier_name' => $row['supplier_name'],
            'enabled' => (bool)$row['enabled'],
            'registered_at' => $row['registered_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function buildFilterWhere(array $filters): array
    {
        $where = [];
        $params = [];

        $imei = trim((string)($filters['imei'] ?? ''));
        if ($imei !== '') {
            $where[] = 'd.imei = :imei';
            $params[':imei'] = $imei;
        }

        $model = trim((string)($filters['model'] ?? ''));
        if ($model !== '') {
            $where[] = 'm.code = :model';
            $params[':model'] = $model;
        }

        $supplier = trim((string)($filters['supplier'] ?? ''));
        if ($supplier !== '') {
            $where[] = 's.name = :supplier';
            $params[':supplier'] = $supplier;
        }

        if (array_key_exists('enabled', $filters) && $filters['enabled'] !== null) {
            $where[] = 'd.enabled = :enabled';
            $params[':enabled'] = $filters['enabled'] ? 1 : 0;
        }

        $online = $filters['online'] ?? null;
        if ($online !== null && $online !== '') {
            // Online state is derived from runtime session/Redis and cannot be filtered purely in SQL.
        }

        $sql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));
        return [$sql, $params];
    }

    private function resolveModelId(array $data): int
    {
        if (isset($data['model_id'])) {
            return (int)$data['model_id'];
        }

        $modelCode = trim((string)($data['model'] ?? ''));
        if ($modelCode === '') {
            throw new \InvalidArgumentException('Device model is required');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM models WHERE code = ? LIMIT 1');
        $stmt->execute([$modelCode]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException("Model code not found: $modelCode");
        }

        return (int)$id;
    }

    private function toMysqlDatetime(string $value): string
    {
        if ($value === 'now') {
            return date('Y-m-d H:i:s');
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }
}
