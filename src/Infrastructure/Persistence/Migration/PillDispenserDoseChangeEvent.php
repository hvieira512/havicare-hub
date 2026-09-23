<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A mudança de estado de uma dose passa a ser acontecimento próprio.
 *
 * As TAGs `0x8131`--`0x8139` chegam por dois caminhos com naturezas opostas: a resposta ao
 * `0x07` é uma leitura de um instante, e a notificação `0x04` é o que aconteceu entre duas
 * leituras. Uma dose falhada não gera `medication_intake` nenhum, e o único sinal dela é esta
 * mudança de estado -- que como telemetria saía a QoS 0.
 */
final class PillDispenserDoseChangeEvent implements Migration
{
    public function version(): string
    {
        return '2026_09_23_pill_dispenser_dose_change_event';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'alarms', 'medication_alarm_change', 'Alteração de dose', 0, 0)
            ON DUPLICATE KEY UPDATE section = VALUES(section), label = VALUES(label)
        ");
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', 'medication_alarm_change', 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");

        // O nível de medicação sai: dizia a mesma coisa que as células restantes, que já
        // mostram «0 de 28». Dois cartões para um facto, e o que ele acrescenta -- que está a
        // acabar -- passa a ser a cor do cartão que tem o número.
        $pdo->exec("
            DELETE FROM model_capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'medication_level'
        ");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'medication_level'
        ");
    }
}
