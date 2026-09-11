<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * As configurações da pulseira que levam valores, e não só um interruptor.
 *
 * Até aqui a MF91 só oferecia os nove interruptores do comando `0xB8`. Estas quatro mudam o
 * que ela mede ou como calcula: a janela em que mede o oxigénio, os limiares do alerta de
 * frequência cardíaca, o tom de pele que calibra o sensor ótico, e os dados do corpo com que
 * ela calcula calorias e composição corporal.
 *
 * A janela do oxigénio é a que desbloqueia a série do tipo 30 -- apneia, hipóxia e carga
 * cardíaca: sem ela a monitorização fica ligada a não medir nada, e a série volta vazia.
 */
final class VeepooParameterisedConfigurations implements Migration
{
    /** Chave, secção e rótulo. Nenhuma se pede ao aparelho: todas são estado que lá fica. */
    private const ADDED = [
        ['blood_oxygen_window', 'health', 'Oxigénio de dia inteiro'],
        ['skin_tone', 'health', 'Tom de pele'],
        ['personal_info', 'health', 'Dados para cálculo'],
        ['heart_rate_alert', 'alarms', 'Alerta de frequência cardíaca'],
    ];

    public function version(): string
    {
        return '2026_09_11_veepoo_parameterised_configurations';
    }

    public function up(PDO $pdo): void
    {
        // Numa base por semear o seeder escreve já o catálogo completo a partir das mesmas
        // definições em código, e escrever aqui antes dele deixava-a sem fornecedores.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $insert = $pdo->prepare('
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES (\'bracelet\', ?, ?, ?, 1, 0)
            ON DUPLICATE KEY UPDATE section = VALUES(section), label = VALUES(label), is_configurable = 1
        ');
        // Pelos modelos que já têm as outras chaves deste protocolo: é o mesmo critério do
        // seeder, sem repetir aqui a decisão de quais são pulseiras Veepoo.
        $link = $pdo->prepare('
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT model_id, \'bracelet\', ?, 1
            FROM model_capabilities
            WHERE device_type = \'bracelet\' AND capability_key = \'heart_rate_continuous\'
        ');

        foreach (self::ADDED as [$key, $section, $label]) {
            $insert->execute([$section, $key, $label]);
            $link->execute([$key]);
        }
    }
}
