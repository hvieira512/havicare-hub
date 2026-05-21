<?php

namespace App\Repository;

use App\Domain\EventNormalizer;

class EventRepository
{
    private \PDO $pdo;
    private const COLUMNS = 'e.id, e.imei, m.code AS model, e.native_type, f.code AS feature, e.native_data, e.generalized_data, e.received_at, e.created_at';
    private const FROM_WITH_DEVICE = 'device_events e LEFT JOIN features f ON f.id = e.feature_id LEFT JOIN devices d ON d.imei = e.imei LEFT JOIN models m ON m.id = d.model_id';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insert(array $event): int
    {
        $feature = isset($event['feature']) && $event['feature'] !== '' ? (string)$event['feature'] : null;
        $nativePayload = is_array($event['nativePayload'] ?? null) ? $event['nativePayload'] : [];
        $generalized = is_array($event['generalizedData'] ?? null)
            ? $event['generalizedData']
            : EventNormalizer::normalize($feature, (string)($event['nativeType'] ?? ''), $nativePayload);

        $featureId = $feature !== null
            ? $this->resolveFeatureId($feature)
            : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO device_events (imei, native_type, feature_id, native_data, generalized_data, received_at)
             VALUES (
                :imei,
                :native_type,
                :feature_id,
                :native_data,
                :generalized_data,
                :received_at
             )'
        );
        $stmt->execute([
            'imei' => $event['imei'],
            'native_type' => $event['nativeType'],
            'feature_id' => $featureId,
            'native_data' => json_encode($nativePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'generalized_data' => json_encode($generalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'received_at' => $event['receivedAt'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findRecent(int $limit = 50, ?int $afterId = null, ?string $imei = null): array
    {
        $where = [];
        $params = [];

        if ($afterId !== null) {
            $where[] = 'e.id > :after_id';
            $params['after_id'] = $afterId;
        }

        if ($imei !== null) {
            $where[] = 'e.imei = :imei';
            $params['imei'] = $imei;
        }

        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM_WITH_DEVICE;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY received_at DESC LIMIT :limit';
        $params['limit'] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn(array $row): array => [
            'id' => (int)$row['id'],
            'imei' => $row['imei'],
            'model' => $row['model'],
            'nativeType' => $row['native_type'],
            'feature' => $row['feature'],
            'nativeData' => json_decode($row['native_data'], true) ?? [],
            'generalizedData' => json_decode($row['generalized_data'], true) ?? [],
            'receivedAt' => (int)$row['received_at'],
        ], $stmt->fetchAll());
    }

    public function latestForImei(string $imei): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM_WITH_DEVICE . ' WHERE e.imei = ? ORDER BY e.received_at DESC LIMIT 1'
        );
        $stmt->execute([$imei]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'imei' => $row['imei'],
            'model' => $row['model'],
            'nativeType' => $row['native_type'],
            'feature' => $row['feature'],
            'nativeData' => json_decode($row['native_data'], true) ?? [],
            'generalizedData' => json_decode($row['generalized_data'], true) ?? [],
            'receivedAt' => (int)$row['received_at'],
        ];
    }

    public function latestForImeiAndFeature(string $imei, string $feature): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM ' . self::FROM_WITH_DEVICE . '
             WHERE e.imei = :imei AND f.code = :feature
             ORDER BY e.received_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            'imei' => $imei,
            'feature' => $feature,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'imei' => $row['imei'],
            'model' => $row['model'],
            'nativeType' => $row['native_type'],
            'feature' => $row['feature'],
            'nativeData' => json_decode($row['native_data'], true) ?? [],
            'generalizedData' => json_decode($row['generalized_data'], true) ?? [],
            'receivedAt' => (int)$row['received_at'],
        ];
    }

    public function count(?string $imei = null): int
    {
        if ($imei !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM device_events WHERE imei = ?');
            $stmt->execute([$imei]);
        } else {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM device_events');
        }
        return (int)$stmt->fetchColumn();
    }

    public function latestForAllImeis(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM_WITH_DEVICE . '
             INNER JOIN (
                 SELECT imei, MAX(received_at) AS max_ts
                 FROM device_events GROUP BY imei
             ) latest ON e.imei = latest.imei AND e.received_at = latest.max_ts'
        );

        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['imei']] = [
                'id' => (int)$row['id'],
                'imei' => $row['imei'],
                'model' => $row['model'],
                'nativeType' => $row['native_type'],
                'feature' => $row['feature'],
                'nativeData' => json_decode($row['native_data'], true) ?? [],
                'generalizedData' => json_decode($row['generalized_data'], true) ?? [],
                'receivedAt' => (int)$row['received_at'],
            ];
        }

        return $result;
    }

    public function purgeOlderThan(string $date, int $keepPerDevice = 1000): int
    {
        $purged = 0;

        $imeis = $this->pdo->query('SELECT DISTINCT imei FROM device_events')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($imeis as $imei) {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM device_events WHERE imei = ?');
            $count->execute([$imei]);
            $total = (int)$count->fetchColumn();

            if ($total > $keepPerDevice) {
                $deleteCount = $total - $keepPerDevice;
                $stmt = $this->pdo->prepare('DELETE FROM device_events WHERE imei = ? ORDER BY received_at ASC LIMIT ?');
                $stmt->execute([$imei, $deleteCount]);
                $purged += $stmt->rowCount();
            }
        }

        $stmt = $this->pdo->prepare('DELETE FROM device_events WHERE created_at < ?');
        $stmt->execute([$date]);
        $purged += $stmt->rowCount();

        return $purged;
    }

    private function resolveFeatureId(string $code): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM features WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }
}
