<?php

namespace Hub\Api\Repository;

use Hub\Domain\DeviceProtocol;
use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class ModelRepository
{
    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(private PDO $pdo)
    {
    }

    // A linha é o superset das três leituras, para o `find` e o `findById` saírem daqui.
    public function all(): array
    {
        return $this->rows ??= TimestampFormatter::normalizeRows($this->pdo
            ->query('SELECT m.*, s.name AS supplier, s.name AS supplier_name, m.image_path AS image FROM models m JOIN suppliers s ON s.id = m.supplier_id ORDER BY s.name, m.commercial_name, m.internal_model')
            ->fetchAll());
    }

    public function find(string $supplier, string $internalModel): ?array
    {
        $supplier = mb_strtolower($supplier);
        $internalModel = mb_strtolower($internalModel);
        foreach ($this->all() as $row) {
            if (
                mb_strtolower((string)($row['supplier'] ?? '')) === $supplier
                && mb_strtolower((string)($row['internal_model'] ?? '')) === $internalModel
            ) {
                return $row;
            }
        }

        return null;
    }

    public function findById(int $id): ?array
    {
        foreach ($this->all() as $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }

    public function protocolForModel(string $supplier, string $internalModel): string
    {
        return DeviceProtocol::forModel($supplier, $internalModel);
    }

    public function add(int $supplierId, string $internalModel, string $commercialName, string $deviceType, ?string $imagePath = null): void
    {
        $existing = $this->findBySupplierId($supplierId, $internalModel);
        $storedImagePath = $imagePath ?? (string)($existing['image_path'] ?? '');
        if ($existing === null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$supplierId, $internalModel, $commercialName, $deviceType, $storedImagePath]);
            $this->rows = null;
            return;
        }

        $stmt = $this->pdo->prepare('
            UPDATE models
            SET commercial_name = ?, device_type = ?, image_path = ?
            WHERE supplier_id = ? AND lower(internal_model) = lower(?)
        ');
        $stmt->execute([$commercialName, $deviceType, $storedImagePath, $supplierId, $internalModel]);
        $this->rows = null;
    }

    public function update(int $id, int $supplierId, string $internalModel, string $commercialName, string $deviceType, ?string $imagePath = null): bool
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return false;
        }

        $storedImagePath = $imagePath ?? (string)($existing['image_path'] ?? '');
        $stmt = $this->pdo->prepare('UPDATE models SET supplier_id = ?, internal_model = ?, commercial_name = ?, device_type = ?, image_path = ? WHERE id = ?');
        $stmt->execute([$supplierId, $internalModel, $commercialName, $deviceType, $storedImagePath, $id]);
        $this->rows = null;

        return $stmt->rowCount() > 0;
    }

    /**
     * O par que a chave única `uq_models_supplier_internal_model` protege.
     *
     * O criar não tem id para excluir da procura, e o `0` não é o de nenhuma linha.
     */
    public function exists(int $supplierId, string $internalModel): bool
    {
        return $this->existsForDifferentId(0, $supplierId, $internalModel);
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
        $this->rows = null;
    }

    private function findBySupplierId(int $supplierId, string $internalModel): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM models WHERE supplier_id = ? AND lower(internal_model) = lower(?)');
        $stmt->execute([$supplierId, $internalModel]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Que fornecedores fazem cada tipo de dispositivo, tirado dos modelos catalogados.
     *
     * Havia uma tabela `supplier_device_types` para isto, escrita só a inserir e só pelos
     * caminhos daqui. Apagar o último relógio de um fornecedor, ou mudar-lhe o tipo, deixava
     * lá o par a afirmar que ele ainda fazia relógios. Derivado não pode divergir.
     *
     * @return list<array{supplier_id: int, supplier: string, device_type: string}>
     */
    public function supplierDeviceTypes(): array
    {
        return $this->pdo
            ->query("
                SELECT DISTINCT
                    m.supplier_id,
                    s.name AS supplier,
                    m.device_type
                FROM models m
                INNER JOIN suppliers s ON s.id = m.supplier_id
                ORDER BY FIELD(m.device_type, 'watch', 'ncs', 'radar', 'gateway', 'diaper_sensor', 'bracelet'), s.name
            ")
            ->fetchAll();
    }
}
