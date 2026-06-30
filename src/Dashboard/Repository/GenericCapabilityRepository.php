<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class GenericCapabilityRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?string $deviceType = null): array
    {
        if ($deviceType === null || trim($deviceType) === '') {
            return $this->pdo
                ->query('SELECT id, device_type, section, capability_key, label, is_telemetry, is_configurable, is_requestable, sort_order, created_at, updated_at FROM capabilities ORDER BY FIELD(device_type, \'watch\', \'ncs\', \'radar\'), FIELD(section, \'telemetry\', \'health\', \'contacts\', \'alarms\', \'settings_system\'), sort_order, capability_key')
                ->fetchAll();
        }

        $stmt = $this->pdo->prepare('SELECT id, device_type, section, capability_key, label, is_telemetry, is_configurable, is_requestable, sort_order, created_at, updated_at FROM capabilities WHERE device_type = ? ORDER BY FIELD(section, \'telemetry\', \'health\', \'contacts\', \'alarms\', \'settings_system\'), sort_order, capability_key');
        $stmt->execute([$deviceType]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, device_type, section, capability_key, label, is_telemetry, is_configurable, is_requestable, sort_order, created_at, updated_at FROM capabilities WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return list<string>
     */
    public function keysForDeviceType(string $deviceType): array
    {
        $stmt = $this->pdo->prepare('SELECT capability_key FROM capabilities WHERE device_type = ? ORDER BY capability_key');
        $stmt->execute([$deviceType]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    public function findIdByDeviceTypeAndKey(string $deviceType, string $key): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $stmt->execute([$deviceType, $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int)$value;
    }
}
