<?php

namespace Hub\Dashboard\Repository;

use Hub\Dashboard\TimestampFormatter;
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
        return TimestampFormatter::normalizeRows($this->pdo
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
            ->fetchAll());
    }

    public function upsert(int $supplierId, string $deviceType): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO supplier_device_types (supplier_id, device_type)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$supplierId, $deviceType]);
    }
}
