<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Tira a reposição de fábrica do catálogo do dispensador nas bases que já a têm.
 *
 * O M228 só aponta para o hub porque o fornecedor lhe mandou essa configuração, e uma
 * reposição devolve-o ao servidor dele. Só as linhas do dispensador: a mesma chave existe nos
 * relógios, onde uma reposição é recuperável e continua a fazer sentido.
 */
final class PillDispenserWithoutFactoryReset implements Migration
{
    public function version(): string
    {
        return '2026_09_21_pill_dispenser_without_factory_reset';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            DELETE FROM model_capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'reset_device'
        ");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'reset_device'
        ");
    }
}
