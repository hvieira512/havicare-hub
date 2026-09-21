<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A acção que manda o dispensador parar de cifrar o que envia.
 *
 * O M228 sai de fábrica com o `0x8005` ligado e cifra o corpo de tudo o que envia em
 * AES128-CFB. A especificação nunca diz a chave, e por isso o hub recebe um heartbeat por
 * minuto de que só consegue ler o cabeçalho -- identidade certa, corpo ilegível, telemetria
 * nenhuma. O mesmo `0x8005` é um parâmetro de configuração escrevível, e é assim que se
 * desliga sem precisar de chave.
 */
final class PillDispenserEncryptionSwitch implements Migration
{
    public function version(): string
    {
        return '2026_09_21_pill_dispenser_encryption_switch';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'settings_system', 'disable_encryption', 'Desligar cifra de dados', 0, 1)
            ON DUPLICATE KEY UPDATE
                section = VALUES(section),
                label = VALUES(label),
                is_configurable = VALUES(is_configurable),
                is_requestable = VALUES(is_requestable)
        ");

        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', 'disable_encryption', 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");
    }
}
