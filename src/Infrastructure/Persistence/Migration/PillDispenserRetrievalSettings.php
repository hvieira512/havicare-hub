<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * As definições da toma que o aparelho suporta e o hub não expunha.
 *
 * Os dois tempos — quando avisar de atraso e quando desistir — decidem se uma dose por tomar
 * chega a alguém como alerta ou fica em silêncio. De fábrica são trinta e sessenta minutos, e
 * mudá-los obrigava a escrever as TAGs à mão por script.
 *
 * As outras três dizem quantos compartimentos estão carregados, mandam o prato rodar até um
 * deles, e suspendem a medicação por uns minutos — para uma ida ao hospital, por exemplo.
 *
 * O que ficou de fora, de propósito: mudar o servidor a que o aparelho se liga
 * (`0xA021`–`0xA023`). É a única ordem que nos pode fazer perder o aparelho.
 */
final class PillDispenserRetrievalSettings implements Migration
{
    private const SETTINGS = [
        ['health', 'retrieval_warning', 'Avisar de atraso ao fim de'],
        ['health', 'retrieval_timeout', 'Dar como falhada ao fim de'],
        ['health', 'loaded_cells', 'Compartimentos carregados'],
        ['settings_system', 'rotate_to_cell', 'Rodar até ao compartimento'],
        ['settings_system', 'medication_pause', 'Pausar medicação'],
    ];

    public function version(): string
    {
        return '2026_09_22_pill_dispenser_retrieval_settings';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $capability = $pdo->prepare("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', ?, ?, ?, 1, 0)
            ON DUPLICATE KEY UPDATE
                section = VALUES(section),
                label = VALUES(label),
                is_configurable = VALUES(is_configurable),
                is_requestable = VALUES(is_requestable)
        ");
        $model = $pdo->prepare("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', ?, 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");

        foreach (self::SETTINGS as [$section, $key, $label]) {
            $capability->execute([$section, $key, $label]);
            $model->execute([$key]);
        }
    }
}
