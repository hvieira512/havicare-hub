<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Deixa cair `suppliers.enabled`.
 *
 * Um fornecedor esta no codigo ou nao esta; nao ha nada no painel nem na API que o desligue,
 * e todas as consultas contra `suppliers` juntam por `id` e por `name`.
 */
final class Version2026082601DropSupplierEnabled implements Migration
{
    public function version(): string
    {
        return '2026082601_drop_supplier_enabled';
    }

    public function up(PDO $pdo): void
    {
        (new MysqlSchema($pdo))->dropColumn('suppliers', 'enabled');
    }
}
