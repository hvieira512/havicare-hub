<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Tira do catálogo as duas ordens que este firmware não serve.
 *
 * Rodar até um compartimento (`0xA124`) e pausar a medicação (`0xA125`) estão na especificação
 * da série M2, mas este firmware recusa-as com «TAG inválida» e a descoberta de parâmetros
 * não as anuncia. Quando entrar um firmware que as sirva, voltam.
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
