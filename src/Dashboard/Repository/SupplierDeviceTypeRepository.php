<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class SupplierDeviceTypeRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array{supplier_id: int, supplier: string, enabled: int, device_type: string, created_at: string, updated_at: string}>
     */
    public function all(): array
    {
        return $this->pdo
            ->query("
                SELECT
                    sdt.supplier_id,
                    s.name AS supplier,
                    s.enabled,
                    sdt.device_type,
                    sdt.created_at,
                    sdt.updated_at
                FROM supplier_device_types sdt
                INNER JOIN suppliers s ON s.id = sdt.supplier_id
                ORDER BY FIELD(sdt.device_type, 'watch', 'ncs', 'radar'), s.name
            ")
            ->fetchAll();
    }

    public function upsert(int $supplierId, string $deviceType): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('
            INSERT INTO supplier_device_types (supplier_id, device_type, created_at, updated_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)
        ');
        $stmt->execute([$supplierId, $deviceType, $now, $now]);
    }
}
