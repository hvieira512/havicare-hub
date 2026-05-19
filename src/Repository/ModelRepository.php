<?php

namespace App\Repository;

class ModelRepository
{
    private const TABLE = 'models m JOIN suppliers s ON s.id = m.supplier_id';
    private const COLS = 'm.id, m.supplier_id, s.name AS supplier_name, m.code, m.name, m.protocol, m.transport, m.enabled, m.created_at, m.updated_at';

    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLS . '
             FROM ' . self::TABLE . '
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

    public function list(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = ($page - 1) * $limit;

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLS . '
             FROM ' . self::TABLE
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
            'SELECT COUNT(*) FROM ' . self::TABLE . $where
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO models
                (supplier_id, code, name, protocol, transport, enabled)
             VALUES
                (:supplier_id, :code, :name, :protocol, :transport, :enabled)'
        );

        $stmt->execute($this->serialize($data));
        return (int)$this->pdo->lastInsertId();
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
            'SELECT ' . self::COLS . '
             FROM ' . self::TABLE
        );

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    private function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'supplier_id' => (int)$row['supplier_id'],
            'supplier_name' => $row['supplier_name'],
            'code' => $row['code'],
            'name' => $row['name'],
            'protocol' => $row['protocol'],
            'transport' => $row['transport'],
            'enabled' => (bool)$row['enabled'],
            'passive' => $this->mappingPassive((int)$row['id']),
            'active' => $this->mappingActive((int)$row['id']),
            'features' => $this->mappingFeatures((int)$row['id']),
            'native_mappings' => $this->mappingRows((int)$row['id']),
            'command_metadata' => [],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function buildWhere(array $filters): array
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

    private function serialize(array $data): array
    {
        $serialized = $data;

        if (array_key_exists('enabled', $serialized)) {
            $serialized['enabled'] = $serialized['enabled'] ? 1 : 0;
        }
        unset($serialized['passive'], $serialized['active'], $serialized['features'], $serialized['command_metadata'], $serialized['source_doc']);

        return $serialized;
    }

    private function decodeJsonArray(mixed $value): array
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

    private function decodeJsonObject(mixed $value): array
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

    private function mappingRows(int $modelId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mfm.id, mfm.native_type, mfm.is_active, mfm.description, mfm.enabled,
                    f.code AS feature_code
             FROM model_feature_mappings mfm
             LEFT JOIN features f ON f.id = mfm.feature_id
             WHERE mfm.model_id = :model_id
             ORDER BY mfm.native_type ASC'
        );
        $stmt->execute(['model_id' => $modelId]);

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'nativeType' => (string)$row['native_type'],
            'isActive' => (bool)$row['is_active'],
            'feature' => $row['feature_code'] !== null ? (string)$row['feature_code'] : null,
            'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'enabled' => (bool)$row['enabled'],
        ], $stmt->fetchAll());
    }

    private function mappingPassive(int $modelId): array
    {
        $types = [];
        foreach ($this->mappingRows($modelId) as $row) {
            if (($row['enabled'] ?? true) === true && ($row['isActive'] ?? false) === false) {
                $types[] = (string)$row['nativeType'];
            }
        }
        return array_values(array_unique($types));
    }

    private function mappingActive(int $modelId): array
    {
        $types = [];
        foreach ($this->mappingRows($modelId) as $row) {
            if (($row['enabled'] ?? true) === true && ($row['isActive'] ?? false) === true) {
                $types[] = (string)$row['nativeType'];
            }
        }
        return array_values(array_unique($types));
    }

    private function mappingFeatures(int $modelId): array
    {
        $features = [];
        foreach ($this->mappingRows($modelId) as $row) {
            if (($row['enabled'] ?? true) !== true) {
                continue;
            }
            $feature = $row['feature'] ?? null;
            if (!is_string($feature) || $feature === '') {
                continue;
            }
            if (!isset($features[$feature])) {
                $features[$feature] = ['passive' => [], 'active' => []];
            }
            $bucket = ($row['isActive'] ?? false) ? 'active' : 'passive';
            $features[$feature][$bucket][] = (string)$row['nativeType'];
        }
        foreach ($features as $feature => $maps) {
            $features[$feature]['passive'] = array_values(array_unique($maps['passive']));
            $features[$feature]['active'] = array_values(array_unique($maps['active']));
        }
        ksort($features);
        return $features;
    }

    public function listFeatureMappingsByCode(string $code): array
    {
        $model = $this->findByCode($code);
        if ($model === null) {
            return [];
        }

        return $this->mappingRows((int)$model['id']);
    }

    public function replaceFeatureMappingsByCode(string $code, array $mappings): void
    {
        $model = $this->findByCode($code);
        if ($model === null) {
            throw new \InvalidArgumentException('model_not_found');
        }
        $modelId = (int)$model['id'];

        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM model_feature_mappings WHERE model_id = ?');
            $del->execute([$modelId]);

            if ($mappings !== []) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO model_feature_mappings
                        (model_id, feature_id, native_type, is_active, description, enabled)
                     VALUES
                        (:model_id, :feature_id, :native_type, :is_active, :description, :enabled)'
                );

                foreach ($mappings as $mapping) {
                    $featureId = $this->resolveFeatureId($mapping['feature'] ?? null);
                    $insert->execute([
                        'model_id' => $modelId,
                        'feature_id' => $featureId,
                        'native_type' => (string)$mapping['nativeType'],
                        'is_active' => !empty($mapping['isActive']) ? 1 : 0,
                        'description' => $mapping['description'] ?? null,
                        'enabled' => array_key_exists('enabled', $mapping) ? ((bool)$mapping['enabled'] ? 1 : 0) : 1,
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function upsertFeatureMappingByCode(string $code, array $mapping): void
    {
        $model = $this->findByCode($code);
        if ($model === null) {
            throw new \InvalidArgumentException('model_not_found');
        }
        $modelId = (int)$model['id'];
        $featureId = $this->resolveFeatureId($mapping['feature'] ?? null);

        $stmt = $this->pdo->prepare(
            'INSERT INTO model_feature_mappings
                (model_id, feature_id, native_type, is_active, description, enabled)
             VALUES
                (:model_id, :feature_id, :native_type, :is_active, :description, :enabled)
             ON DUPLICATE KEY UPDATE
                feature_id = VALUES(feature_id),
                is_active = VALUES(is_active),
                description = VALUES(description),
                enabled = VALUES(enabled)'
        );
        $stmt->execute([
            'model_id' => $modelId,
            'feature_id' => $featureId,
            'native_type' => (string)$mapping['nativeType'],
            'is_active' => !empty($mapping['isActive']) ? 1 : 0,
            'description' => $mapping['description'] ?? null,
            'enabled' => array_key_exists('enabled', $mapping) ? ((bool)$mapping['enabled'] ? 1 : 0) : 1,
        ]);
    }

    public function deleteFeatureMappingByCode(string $code, string $nativeType): bool
    {
        $model = $this->findByCode($code);
        if ($model === null) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM model_feature_mappings WHERE model_id = ? AND native_type = ?');
        $stmt->execute([(int)$model['id'], $nativeType]);
        return $stmt->rowCount() > 0;
    }

    private function resolveFeatureId(mixed $featureCode): ?int
    {
        if (!is_string($featureCode) || trim($featureCode) === '') {
            return null;
        }
        $featureCode = trim($featureCode);
        $name = ucwords(str_replace('_', ' ', $featureCode));
        $stmt = $this->pdo->prepare(
            'INSERT INTO features (code, name, enabled)
             VALUES (:code, :name, 1)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'code' => $featureCode,
            'name' => $name === '' ? $featureCode : $name,
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
