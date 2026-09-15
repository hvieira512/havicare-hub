<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * O sono da pulseira passa a poder ser pedido.
 *
 * É a única grandeza sem outro caminho: os blocos de cinco minutos são relidos sozinhos, mas
 * o registo de sono entrava uma vez só, quando o gateway arrancava. A pulseira responde ao
 * pedido a qualquer momento -- faltava o catálogo declarar que se podia pedir, sem o que o
 * botão não chega sequer a aparecer no ecrã.
 */
final class VeepooSleepOnDemand implements Migration
{
    public function version(): string
    {
        return '2026_09_15_veepoo_sleep_on_demand';
    }

    public function up(PDO $pdo): void
    {
        // Numa base ainda por semear não há nada a acertar: o seeder escreve o catálogo já
        // corrigido, a partir das mesmas definições em código.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("
            UPDATE capabilities SET is_requestable = 1
            WHERE device_type = 'bracelet' AND capability_key = 'sleep'
        ");
    }
}
