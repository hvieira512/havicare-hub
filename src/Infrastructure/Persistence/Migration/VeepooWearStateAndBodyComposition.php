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
     *
     */
    private const REMOVED = ['activity_daily'];

    /**
     * O `ppg` perde a ligação aos modelos Veepoo, mas continua a existir para pulseiras.
     *
     * Esta não exporta onda nenhuma: o SDK do fabricante não expõe leitura de PPG, o caminho
     * de dados crus que ele documenta é personalização de outro projeto e não responde aqui,
     * e o micro-exame -- por onde a onda sairia -- devolve vazio. O que a app chama `ppgs` são
     * as cinco frequências de pulso do bloco, que já saem como `heart_rate`. A capacidade fica
     * no catálogo porque outra pulseira pode tê-la a sério.
     */
    private const UNLINKED = ['ppg'];

    /**
     * Capacidades que a MF91 tinha ligadas a zero e que o hub publica todos os dias.
     *
     * A MF91 entrou nas bases pelo painel e não pelo semeador, e ficou com o catálogo a dizer
     * que não as suporta -- a API respondia isso a quem perguntasse, enquanto a telemetria
     * delas saía na mesma. Todas foram conferidas contra a app do fabricante.
     *
     * A `sleep_apnea` e a `cardiac_load` ficam desligadas de propósito: dependem de se dormir
     * com a pulseira e nunca se viu uma trama delas.
     */
    private const ENABLED = [
        'blood_lipids',
        'uric_acid',
        'met',
        'stress',
        'wear_state',
        'body_composition',
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

        $placeholders = implode(', ', array_fill(0, count(self::REMOVED), '?'));
        $deletions = [
            "DELETE FROM model_capabilities WHERE device_type = 'bracelet' AND capability_key IN ($placeholders)",
            "DELETE FROM capabilities WHERE device_type = 'bracelet' AND capability_key IN ($placeholders)",
        ];
        foreach ($deletions as $sql) {
            $pdo->prepare($sql)->execute(self::REMOVED);
        }

        // Pelos modelos que têm as outras chaves deste protocolo, que é como o seeder os
        // reconhece -- assim uma pulseira de outra marca com PPG a sério não é afetada.
        $unlink = $pdo->prepare("
            DELETE FROM model_capabilities
             WHERE device_type = 'bracelet' AND capability_key = ?
               AND model_id IN (
                   SELECT model_id FROM (
                       SELECT model_id FROM model_capabilities
                        WHERE device_type = 'bracelet' AND capability_key = 'heart_rate_continuous'
                   ) AS veepoo
               )
        ");
        foreach (self::UNLINKED as $key) {
            $unlink->execute([$key]);
        }

        $enable = $pdo->prepare("
            UPDATE model_capabilities SET enabled = 1
             WHERE device_type = 'bracelet' AND capability_key = ?
               AND model_id IN (
                   SELECT model_id FROM (
                       SELECT model_id FROM model_capabilities
                        WHERE device_type = 'bracelet' AND capability_key = 'heart_rate_continuous'
                   ) AS veepoo
               )
        ");
        foreach (self::ENABLED as $key) {
            $enable->execute([$key]);
        }

        // O acumulado do dia tinha um `is_requestable` a zero no modelo, que ganha ao da
        // capacidade por causa do `COALESCE` -- e a API respondia que não se podia pedir.
        $pdo->exec("
            UPDATE model_capabilities mc
              JOIN models m ON m.id = mc.model_id
               SET mc.is_requestable = NULL
             WHERE mc.device_type = 'bracelet' AND mc.capability_key = 'activity'
               AND m.id IN (
                   SELECT model_id FROM (
                       SELECT model_id FROM model_capabilities
                        WHERE device_type = 'bracelet' AND capability_key = 'heart_rate_continuous'
                   ) AS veepoo
               )
        ");

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
