<?php

namespace Hub\Dashboard\Repository;

use Hub\Dashboard\DeviceProtocol;
use PDO;

final class ModelRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo
            ->query('SELECT m.id, m.supplier_id, s.name AS supplier, m.internal_model, m.commercial_name, m.device_type, m.image_path AS image FROM models m JOIN suppliers s ON s.id = m.supplier_id ORDER BY s.name, m.commercial_name, m.internal_model')
            ->fetchAll();
    }

    public function find(string $supplier, string $internalModel): ?array
    {
        $stmt = $this->pdo->prepare('SELECT m.*, s.name AS supplier_name FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE lower(s.name) = lower(?) AND lower(m.internal_model) = lower(?)');
        $stmt->execute([$supplier, $internalModel]);

        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT m.*, s.name AS supplier_name FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE m.id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function protocolForModel(string $supplier, string $internalModel): string
    {
        return DeviceProtocol::forSupplier($supplier);
    }

    public function add(int $supplierId, string $internalModel, string $commercialName, string $deviceType, ?string $imagePath = null): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $existing = $this->findBySupplierId($supplierId, $internalModel);
        $storedImagePath = $imagePath ?? (string)($existing['image_path'] ?? '');
        if ($existing === null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$supplierId, $internalModel, $commercialName, $deviceType, $storedImagePath, $now, $now]);
            $this->ensureSupplierDeviceType($supplierId, $deviceType);
            return;
        }

        $stmt = $this->pdo->prepare('
            UPDATE models
            SET commercial_name = ?, device_type = ?, image_path = ?, updated_at = ?
            WHERE supplier_id = ? AND lower(internal_model) = lower(?)
        ');
        $stmt->execute([$commercialName, $deviceType, $storedImagePath, $now, $supplierId, $internalModel]);
        $this->ensureSupplierDeviceType($supplierId, $deviceType);
    }

    public function update(int $id, int $supplierId, string $internalModel, string $commercialName, string $deviceType, ?string $imagePath = null): bool
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return false;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $storedImagePath = $imagePath ?? (string)($existing['image_path'] ?? '');
        $stmt = $this->pdo->prepare('UPDATE models SET supplier_id = ?, internal_model = ?, commercial_name = ?, device_type = ?, image_path = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$supplierId, $internalModel, $commercialName, $deviceType, $storedImagePath, $now, $id]);
        $this->ensureSupplierDeviceType($supplierId, $deviceType);

        return $stmt->rowCount() > 0;
    }

    public function existsForDifferentId(int $id, int $supplierId, string $internalModel): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM models WHERE id != ? AND supplier_id = ? AND lower(internal_model) = lower(?)');
        $stmt->execute([$id, $supplierId, $internalModel]);

        return (int)$stmt->fetchColumn() > 0;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM models WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function findBySupplierId(int $supplierId, string $internalModel): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM models WHERE supplier_id = ? AND lower(internal_model) = lower(?)');
        $stmt->execute([$supplierId, $internalModel]);

        return $stmt->fetch() ?: null;
    }

    private function ensureSupplierDeviceType(int $supplierId, string $deviceType): void
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
