<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * O dispositivo passa a apontar para o modelo e para a licença pela chave, e larga as cópias dos
 * nomes: renomear uma empresa ou um modelo leva os dispositivos junto, e apagar um em uso recusa.
 */
final class WhitelistReferencesModelAndLicense implements Migration
{
    public function version(): string
    {
        return '2026_10_26_whitelist_references_model_and_license';
    }

    public function up(PDO $pdo): void
    {
        if (!self::hasColumn($pdo, 'supplier')) {
            return;
        }

        if (!self::hasColumn($pdo, 'model_id')) {
            $pdo->exec('ALTER TABLE whitelist
                ADD COLUMN model_id BIGINT UNSIGNED NULL AFTER imei,
                ADD COLUMN license_ref_id BIGINT UNSIGNED NULL AFTER device_type');
        }

        $pdo->exec('
            UPDATE whitelist w
            JOIN suppliers s ON s.name = w.supplier
            JOIN models m ON m.supplier_id = s.id AND m.internal_model = w.model
            SET w.model_id = m.id
        ');
        $pdo->exec('
            UPDATE whitelist w
            JOIN companies c ON c.name = w.company
            JOIN licenses l ON l.company_id = c.id AND l.license_id = w.license_id
            SET w.license_ref_id = l.id
        ');

        // Antes de largar as colunas antigas: um dispositivo sem par perdia o modelo ou o dono.
        $unmatched = $pdo->query('
            SELECT imei FROM whitelist
            WHERE model_id IS NULL OR (license_id IS NOT NULL AND license_ref_id IS NULL)
        ')->fetchAll(PDO::FETCH_COLUMN);
        if ($unmatched !== []) {
            throw new \RuntimeException('Whitelist rows without a model or license to point to: ' . implode(', ', $unmatched));
        }

        $pdo->exec('ALTER TABLE whitelist
            DROP INDEX idx_whitelist_supplier_model,
            DROP INDEX idx_whitelist_license_device_type,
            DROP INDEX idx_whitelist_company,
            DROP COLUMN supplier,
            DROP COLUMN model,
            DROP COLUMN company,
            DROP COLUMN license_id,
            MODIFY model_id BIGINT UNSIGNED NOT NULL,
            ADD KEY idx_whitelist_model (model_id),
            ADD KEY idx_whitelist_license_device_type (license_ref_id, device_type),
            ADD CONSTRAINT fk_whitelist_model FOREIGN KEY (model_id) REFERENCES models(id),
            ADD CONSTRAINT fk_whitelist_license FOREIGN KEY (license_ref_id) REFERENCES licenses(id)');
    }

    private static function hasColumn(PDO $pdo, string $column): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whitelist' AND COLUMN_NAME = ?
        ");
        $stmt->execute([$column]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
