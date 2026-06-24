<?php

namespace Hub\Dashboard\Repository;

use Hub\Dashboard\DeviceMetadata;
use PDO;

final class WhitelistRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo
            ->query('SELECT imei, supplier, model, device_type, license_id, sim_number, device_id, source_system, source_device_id, software FROM whitelist ORDER BY imei')
            ->fetchAll();
    }

    public function get(string $imei): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whitelist WHERE imei = ?');
        $stmt->execute([$imei]);

        return $stmt->fetch() ?: null;
    }

    public function register(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        string $licenseId = '0',
        string $simNumber = '',
        string $deviceId = '',
        string $sourceSystem = '',
        string $sourceDeviceId = '',
        string $software = 'null'
    ): void {
        $deviceType = DeviceMetadata::normalizeDeviceType($deviceType);
        $licenseId = DeviceMetadata::normalizeLicenseId($licenseId);
        $sourceSystem = trim($sourceSystem);
        $sourceDeviceId = trim($sourceDeviceId);
        $software = trim($software);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('
            INSERT INTO whitelist (imei, supplier, model, device_type, license_id, sim_number, device_id, source_system, source_device_id, software, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(imei) DO UPDATE SET
                supplier = excluded.supplier,
                model = excluded.model,
                device_type = excluded.device_type,
                license_id = excluded.license_id,
                sim_number = excluded.sim_number,
                device_id = excluded.device_id,
                source_system = excluded.source_system,
                source_device_id = excluded.source_device_id,
                software = excluded.software,
                updated_at = ?
        ');
        $stmt->execute([$imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $sourceSystem, $sourceDeviceId, $software, $now, $now, $now]);
    }

    public function unregister(string $imei): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM whitelist WHERE imei = ?');
        $stmt->execute([$imei]);
    }
}
