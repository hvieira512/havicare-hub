<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Tira o cartão SIM do catálogo do dispensador.
 *
 * O CCID é um identificador que nunca muda e ninguém o consulta na dashboard: cada leitura de
 * estado deixava mais uma linha na lista de eventos a repetir o mesmo número. O adaptador
 * continua a descodificar o `0x8009` — descodificar e publicar são decisões separadas.
 */
final class PillDispenserWithoutSimCard implements Migration
{
    public function version(): string
    {
        return '2026_09_23_pill_dispenser_without_sim_card';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            DELETE FROM model_capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'sim_card'
        ");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'sim_card'
        ");
    }
}
