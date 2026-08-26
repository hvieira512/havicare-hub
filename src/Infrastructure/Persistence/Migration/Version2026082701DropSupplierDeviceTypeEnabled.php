<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Deixa cair `supplier_device_types.enabled`.
 *
 * A tabela diz que pares fornecedor x tipo de dispositivo existem, e existir e a unica
 * coisa que ha para dizer sobre um par: nenhuma consulta le a coluna e nenhum `INSERT` lhe
 * da valor -- todos os que escrevem aqui nomeiam `supplier_id` e `device_type` e mais nada.
 */
final class Version2026082701DropSupplierDeviceTypeEnabled implements Migration
{
    public function version(): string
    {
        return '2026082701_drop_supplier_device_type_enabled';
    }

    public function up(PDO $pdo): void
    {
        (new MysqlSchema($pdo))->dropColumn('supplier_device_types', 'enabled');
    }
}
