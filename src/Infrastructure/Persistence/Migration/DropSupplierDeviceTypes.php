<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Larga a `supplier_device_types` de uma base que já existia.
 *
 * A tabela respondia a uma pergunta -- que fornecedores fazem cada tipo de dispositivo --
 * que os modelos catalogados já respondem. Era escrita num sítio só, o `ModelRepository`, e
 * só a inserir: apagar o último relógio de um fornecedor, ou mudar-lhe o tipo, deixava lá o
 * par a afirmar que ele ainda fazia relógios. Em produção os oito pares estavam certos por
 * nunca ter sido apagado nem retipificado nenhum modelo.
 *
 * Passou a `SELECT DISTINCT` sobre a `models`, no `ModelRepository::supplierDeviceTypes()`.
 * Derivado não pode divergir.
 */
final class DropSupplierDeviceTypes implements Migration
{
    public function version(): string
    {
        return '2026_09_03_drop_supplier_device_types';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS supplier_device_types');
    }
}
