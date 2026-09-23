<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Arruma o catálogo do dispensador para quem nunca viu o aparelho conseguir administrá-lo.
 *
 * Três coisas. O cartão SIM passa a ser capacidade própria: era publicado dentro do estado do
 * dispositivo, ao lado do sinal, e ficava perdido entre vinte e cinco linhas por hora a dizer
 * `-25 dBm` — uma coisa é uma leitura que muda ao minuto, outra é o cartão que está lá dentro.
 *
 * Duas acções mudam de sítio para o sítio que a pessoa procura: dispensar é um acto sobre a
 * medicação e vai para Saúde, silenciar cala um alarme e vai para Alarmes. O «Dispensar agora»
 * era também o único ponto onde o catálogo de capacidades e as definições discordavam --
 * aquele dizia saúde, estas diziam sistema, e são estas que mandam no que aparece no modal.
 *
 * E as etiquetas passam a dizer o que a acção faz: «Parâmetros de controlo» não diz nada a
 * quem chega; «Que ordens este aparelho obedece» diz.
 */
final class PillDispenserCatalogueTidyUp implements Migration
{
    private const RENAMED = [
        'sync_configuration' => ['settings_system', 'Sincronizar configuração'],
        'device_status' => ['telemetry', 'Estado do dispositivo'],
        'supported_configuration' => ['settings_system', 'Que configurações este aparelho aceita'],
        'supported_status' => ['settings_system', 'Que leituras este aparelho sabe dar'],
        'supported_control' => ['settings_system', 'Que ordens este aparelho obedece'],
        'device_language' => ['settings_system', 'Idioma do ecrã'],
        'calibrate_clock' => ['settings_system', 'Acertar o relógio do aparelho'],
        'mute_alarm' => ['alarms', 'Silenciar o alarme a tocar'],
        'dispense_now' => ['health', 'Dispensar agora'],
    ];

    public function version(): string
    {
        return '2026_09_23_pill_dispenser_catalogue_tidy_up';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'telemetry', 'sim_card', 'Cartão SIM', 0, 0)
            ON DUPLICATE KEY UPDATE section = VALUES(section), label = VALUES(label)
        ");
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', 'sim_card', 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");

        $update = $pdo->prepare("
            UPDATE capabilities
            SET section = ?, label = ?
            WHERE device_type = 'pill_dispenser' AND capability_key = ?
        ");
        foreach (self::RENAMED as $key => [$section, $label]) {
            $update->execute([$section, $label, $key]);
        }
    }
}
