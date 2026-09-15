<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Dá às pulseiras a capacidade que faltava para o relatório de sono do firmware.
 *
 * A pulseira guarda três noites e entrega-as já calculadas: fases, curva, e sete pontuações
 * sobre a noite. O `sleep` leva a noite -- é o contrato que os relógios já usam --, mas
 * nenhum relógio pontua o sono, e as pontuações não tinham onde ir. Sem esta chave saíam no
 * MQTT sem estar declaradas, que é a falha calada de que o `CLAUDE.md` fala: quem integra
 * subscreve o que o catálogo promete.
 */
final class VeepooSleepQuality implements Migration
{
    public function version(): string
    {
        return '2026_09_15_veepoo_sleep_quality';
    }

    public function up(PDO $pdo): void
    {
        // Numa base ainda por semear não há nada a acertar: o seeder escreve o catálogo a
        // partir das mesmas definições em código, e é a tabela vazia que lhe diz que tem de
        // semear.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('bracelet', 'telemetry', 'sleep_quality', 'Qualidade do sono', 0, 0)
            ON DUPLICATE KEY UPDATE label = VALUES(label)
        ");

        // Aos modelos que já têm o sono, porque é a mesma trama que traz as duas coisas.
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT model_id, 'bracelet', 'sleep_quality', 1
            FROM model_capabilities
            WHERE device_type = 'bracelet' AND capability_key = 'sleep'
        ");
    }
}
