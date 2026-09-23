<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Tira o cartão SIM do catálogo do dispensador.
 *
 * Tinha acabado de ser separado do estado do dispositivo para capacidade própria, com o
 * argumento de que uma coisa é uma leitura que muda ao minuto e outra é o cartão que está lá
 * dentro. O argumento estava certo e a conclusão não: o CCID é um identificador que nunca
 * muda, ninguém o consulta na dashboard, e quem precise dele vai buscá-lo à ficha do
 * dispositivo. O cartão só ocupava um lugar na telemetria e cada leitura de estado deixava
 * mais uma linha na lista de eventos a repetir o mesmo número.
 *
 * O adaptador continua a descodificar o `0x8009` — é preciso para não tropeçar no TLV — mas
 * descodificar e publicar são decisões separadas.
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
