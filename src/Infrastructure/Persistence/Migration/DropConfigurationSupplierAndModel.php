<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Larga o `supplier` e o `model` da `device_configurations`.
 *
 * Eram cópia do que a `whitelist` diz sobre o IMEI, escritas em três caminhos e lidas em
 * nenhum -- nem por nome numa consulta, nem a partir do `SELECT *` que a `allForImei`
 * devolve. O modelo que a API mostra vem da `models`, pelo IMEI.
 *
 * A cópia divergiu de facto: as três linhas órfãs do IMEI `000060060298220`, removidas nesta
 * limpeza, declaravam `D41` em duas e `D45 Pro` na terceira, para o mesmo aparelho.
 */
final class DropConfigurationSupplierAndModel implements Migration
{
    private const COLUMNS = ['supplier', 'model'];

    public function version(): string
    {
        return '2026_09_03_drop_configuration_supplier_and_model';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::COLUMNS as $column) {
            if ($this->hasColumn($pdo, $column)) {
                $pdo->exec("ALTER TABLE device_configurations DROP COLUMN {$column}");
            }
        }
    }

    private function hasColumn(PDO $pdo, string $column): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute(['device_configurations', $column]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
