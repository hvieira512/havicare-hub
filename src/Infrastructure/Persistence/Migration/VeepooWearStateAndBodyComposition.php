<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Domain\Capability\CapabilityCatalog;
use PDO;

/**
 * Duas capacidades novas das pulseiras Veepoo: estado de uso e composição corporal.
 *
 * O estado de uso vinha em todos os blocos de cinco minutos e era deitado fora. Sem ele, um
 * bloco de zeros por a pulseira estar na mesinha de cabeceira é indistinguível de um bloco
 * de zeros de quem está sentado -- a mesma leitura com significados opostos, e num dia
 * inteiro são 97% dos blocos.
 *
 * A composição corporal mede-se pelos elétrodos do ECG e devolve catorze grandezas de uma
 * vez. A app do fabricante já a oferecia e o hub não a conhecia de todo.
 *
 * E o total do dia, que a pulseira conta sozinha: o `activity` dos blocos é o que se andou em
 * cinco minutos, e quem quisesse os passos de hoje tinha de somar duzentos e oitenta e oito.
 */
final class VeepooWearStateAndBodyComposition implements Migration
{
    /** Chave, rótulo e se pode ser pedida ao aparelho. */
    private const ADDED = [
        ['wear_state', 'Estado de uso', false],
        ['body_composition', 'Composição corporal', true],
        ['activity_daily', 'Total do dia', true],
        ['firmware_version', 'Versão de firmware', false],
    ];

    public function version(): string
    {
        return '2026_09_11_veepoo_wear_state_and_body_composition';
    }

    public function up(PDO $pdo): void
    {
        // Numa base por semear o seeder escreve já o catálogo completo, a partir das mesmas
        // definições em código; escrever aqui antes dele deixava-a sem fornecedores.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $insert = $pdo->prepare("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('bracelet', 'telemetry', ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE label = VALUES(label), is_requestable = VALUES(is_requestable)
        ");
        $link = $pdo->prepare("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT model_id, 'bracelet', ?, 1
            FROM model_capabilities
            WHERE device_type = 'bracelet' AND capability_key = 'heart_rate_continuous'
        ");

        foreach (self::ADDED as [$key, $label, $requestable]) {
            $insert->execute([$key, $label, $requestable ? 1 : 0]);
            // Pelos modelos que já têm as outras chaves deste protocolo: é o mesmo critério
            // do seeder, sem repetir aqui a decisão de quais são pulseiras Veepoo.
            $link->execute([$key]);
        }

        // Os rótulos já semeados ficam no que eram, e o do `activity` passou a dizer a janela
        // -- sem isto o ecrã punha «Atividade: 0 passos» ao lado de «Total do dia: 216».
        $label = $pdo->prepare("
            UPDATE capabilities SET label = ?
            WHERE device_type = 'bracelet' AND capability_key = ? AND label <> ?
        ");
        foreach (CapabilityCatalog::definitions() as $definition) {
            if (($definition['deviceType'] ?? '') !== 'bracelet') {
                continue;
            }
            $label->execute([$definition['label'], $definition['key'], $definition['label']]);
        }
    }
}
