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
 * E os passos de cada bloco de cinco minutos, separados do acumulado do dia: o `activity` é o
 * contador desde a meia-noite, como nos relógios, e o `steps` diz quando os passos foram
 * dados. A distância e as calorias do bloco não entram porque não são medições -- são os
 * passos vezes uma constante.
 */
final class VeepooWearStateAndBodyComposition implements Migration
{
    /** Chave, rótulo e se pode ser pedida ao aparelho. */
    private const ADDED = [
        ['wear_state', 'Estado de uso', false],
        ['body_composition', 'Composição corporal', true],
        ['steps', 'Passos', false],
        ['firmware_version', 'Versão de firmware', false],
    ];

    /**
     * O que se chamou `activity_daily` antes de se perceber que era `activity`.
     *
     * O acumulado do dia é o que `activity` sempre significou nos relógios, onde o `steps` do
     * aparelho é um contador desde a meia-noite. Dois nomes para a mesma grandeza obrigavam
     * quem integra a tratar por duas coisas o que é uma só.
     */
    private const REMOVED = ['activity_daily'];

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

        $placeholders = implode(', ', array_fill(0, count(self::REMOVED), '?'));
        $deletions = [
            "DELETE FROM model_capabilities WHERE device_type = 'bracelet' AND capability_key IN ($placeholders)",
            "DELETE FROM capabilities WHERE device_type = 'bracelet' AND capability_key IN ($placeholders)",
        ];
        foreach ($deletions as $sql) {
            $pdo->prepare($sql)->execute(self::REMOVED);
        }

        // O acumulado do dia passa a poder ser pedido: é um contador que a pulseira já tem, e
        // responde no instante como a bateria.
        $pdo->exec("
            UPDATE capabilities SET is_requestable = 1
            WHERE device_type = 'bracelet' AND capability_key = 'activity'
        ");

        // Os rótulos já semeados ficam no que eram, e o catálogo em código mudou-os.
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
