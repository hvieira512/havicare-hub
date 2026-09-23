<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Põe o dispensador de comprimidos no catálogo das bases que já existiam.
 *
 * O tipo de dispositivo, o fornecedor e o modelo são novos, e nenhum deles chega por código:
 * o seeder só corre numa base vazia. A ordem não é arbitrária -- a `device_types` é o destino
 * das chaves estrangeiras da `models` e da `capabilities`, e a `model_capabilities` precisa
 * das duas.
 */
final class PillDispenserCatalog implements Migration
{
    public function version(): string
    {
        return '2026_09_18_pill_dispenser_catalog';
    }

    public function up(PDO $pdo): void
    {
        // Numa base ainda por semear não há nada a acertar: o seeder escreve o mesmo a partir
        // das definições em código, e é a tabela vazia que lhe diz que tem de semear.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("INSERT IGNORE INTO device_types (device_type) VALUES ('pill_dispenser')");
        $pdo->exec("INSERT IGNORE INTO suppliers (name) VALUES ('Zayata')");
        // O nome comercial não repete o fornecedor: a dashboard já o mostra ao lado, e
        // "Zayata Zayata M228" era o que saía no cabeçalho do aparelho.
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT id, 'M228', 'M228', 'pill_dispenser', ''
            FROM suppliers WHERE name = 'Zayata'
        ");

        // Telemetria, eventos, o que se configura e o que se pede.
        $pdo->exec("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES
                ('pill_dispenser', 'telemetry', 'battery', 'Bateria', 0, 0),
                ('pill_dispenser', 'telemetry', 'medication_level', 'Nível de medicação', 0, 0),
                ('pill_dispenser', 'telemetry', 'cells_remaining', 'Células restantes', 0, 0),
                ('pill_dispenser', 'telemetry', 'temperature', 'Temperatura', 0, 0),
                ('pill_dispenser', 'telemetry', 'humidity', 'Humidade', 0, 0),
                ('pill_dispenser', 'telemetry', 'device_status', 'Estado do dispositivo', 0, 1),
                ('pill_dispenser', 'alarms', 'medication_intake', 'Toma de medicação', 0, 0),
                ('pill_dispenser', 'alarms', 'device_fault', 'Avaria', 0, 0),
                ('pill_dispenser', 'alarms', 'help_call', 'Chamada de ajuda', 0, 0),
                ('pill_dispenser', 'health', 'medication_reminders', 'Plano de medicação', 1, 0),
                ('pill_dispenser', 'health', 'medication_period', 'Período do plano', 1, 0),
                ('pill_dispenser', 'health', 'early_dispense', 'Toma antecipada', 1, 0),
                ('pill_dispenser', 'health', 'child_lock', 'Bloqueio de criança', 1, 0),
                ('pill_dispenser', 'alarms', 'alarm_volume', 'Volume', 1, 0),
                ('pill_dispenser', 'alarms', 'alarm_ringtone', 'Tipo de toque', 1, 0),
                ('pill_dispenser', 'alarms', 'do_not_disturb', 'Não incomodar', 1, 0),
                ('pill_dispenser', 'settings_system', 'device_language', 'Idioma', 1, 0),
                ('pill_dispenser', 'settings_system', 'time_zone', 'Fuso horário', 1, 0),
                ('pill_dispenser', 'settings_system', 'sync_configuration', 'Sincronizar configuração', 0, 1),
                ('pill_dispenser', 'health', 'dispense_now', 'Dispensar agora', 0, 1),
                ('pill_dispenser', 'alarms', 'mute_alarm', 'Silenciar', 0, 1),
                ('pill_dispenser', 'settings_system', 'calibrate_clock', 'Calibrar relógio', 0, 1),
                ('pill_dispenser', 'settings_system', 'reset_tray', 'Repor o prato', 0, 1),
                ('pill_dispenser', 'settings_system', 'restart_device', 'Reiniciar', 0, 1)
            ON DUPLICATE KEY UPDATE
                section = VALUES(section),
                label = VALUES(label),
                is_configurable = VALUES(is_configurable),
                is_requestable = VALUES(is_requestable)
        ");

        // Todas ao M228: o protocolo entrega-as todas, e portanto a matriz do modelo não tem
        // lacunas para abrir. Uma desligada à mão depois fica com `enabled = 0` e o
        // `INSERT IGNORE` não lhe toca.
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', c.capability_key, 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            JOIN capabilities c ON c.device_type = 'pill_dispenser'
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");
    }
}
