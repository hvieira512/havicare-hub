<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * O estado de toma dos nove alarmes passa a ser telemetria do dispensador.
 *
 * O evento de toma (`0x03`) é a leitura rica — hora prevista, hora real, célula — e chega
 * cifrado. As TAGs `0x8131`–`0x8139` respondem à mesma pergunta por outro caminho: são
 * estado, pedem-se num `0x07`, e uma resposta a pedido nosso vem sempre em claro.
 */
final class PillDispenserAlarmStatus implements Migration
{
    public function version(): string
    {
        return '2026_09_22_pill_dispenser_alarm_status';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'telemetry', 'medication_alarm_status', 'Estado dos alarmes', 0, 0)
            ON DUPLICATE KEY UPDATE
                section = VALUES(section),
                label = VALUES(label),
                is_configurable = VALUES(is_configurable),
                is_requestable = VALUES(is_requestable)
        ");

        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', 'medication_alarm_status', 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");
    }
}
