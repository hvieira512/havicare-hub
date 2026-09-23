<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Cada leitura que o `0x07` enche passa a pedir-se por si.
 *
 * Havia um `device_status` que não publicava nada e existia só para ser o botão dessa trama:
 * quem quisesse a temperatura tinha de saber que a ia buscar clicando numa coisa chamada
 * «estado do dispositivo», dentro do modal de configurações, enquanto o mosaico da
 * temperatura ficava a olhar sem responder ao clique.
 *
 * A trama é uma só e enche sete leituras — bateria, temperatura, humidade, ligação à rede,
 * compartimentos, tampa e o estado dos nove alarmes —, e por isso são as sete que a pedem. O
 * clique fica no mosaico que a pessoa está a olhar quando o quer, e o botão à parte deixa de
 * fazer falta.
 */
final class PillDispenserReadingsAreRequestable implements Migration
{
    private const REQUESTABLE = [
        'battery',
        'cells_remaining',
        'connectivity',
        'humidity',
        'lid_state',
        'medication_alarm_status',
        'temperature',
    ];

    public function version(): string
    {
        return '2026_09_23_pill_dispenser_readings_are_requestable';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count(self::REQUESTABLE), '?'));
        $pdo->prepare("
            UPDATE capabilities
            SET is_requestable = 1
            WHERE device_type = 'pill_dispenser' AND capability_key IN ({$placeholders})
        ")->execute(self::REQUESTABLE);

        // O modelo pode ter a bandeira dele à frente da do catálogo, e uma leitura que o
        // catálogo passa a servir tem de ficar pedível também aí -- senão a dashboard mostra
        // o mosaico e o clique não faz nada.
        $pdo->prepare("
            UPDATE model_capabilities
            SET is_requestable = 1
            WHERE device_type = 'pill_dispenser'
              AND capability_key IN ({$placeholders})
              AND is_requestable IS NOT NULL
        ")->execute(self::REQUESTABLE);

        $pdo->exec("
            DELETE FROM model_capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'device_status'
        ");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'device_status'
        ");
    }
}
