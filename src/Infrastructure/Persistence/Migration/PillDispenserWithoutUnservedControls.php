<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Tira do catálogo as duas ordens que este firmware não serve.
 *
 * Rodar até um compartimento (`0xA124`) e pausar a medicação (`0xA125`) estão na especificação
 * da série M2, mas foram acrescentadas numa versão posterior à que o aparelho de ensaio corre.
 * Ele recusa-as com «TAG inválida», e o hub retentava-as de minuto a minuto até desistir.
 *
 * Foram declaradas a partir do documento sem se confrontar com a resposta que o próprio
 * aparelho já tinha dado: a descoberta de parâmetros devolveu doze TAGs de controlo, e nenhuma
 * delas é estas duas. Quando entrar um firmware que as anuncie, voltam.
 */
final class PillDispenserWithoutUnservedControls implements Migration
{
    public function version(): string
    {
        return '2026_09_23_pill_dispenser_without_unserved_controls';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            DELETE FROM model_capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key IN ('rotate_to_cell', 'medication_pause')
        ");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key IN ('rotate_to_cell', 'medication_pause')
        ");
    }
}
