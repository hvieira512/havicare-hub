<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Domain\Capability\CapabilityCatalog;
use PDO;

/**
 * Acerta o catálogo das pulseiras pelo que a MF91 diz de si própria.
 *
 * Ao ler as definições, o aparelho responde a cada interruptor com o estado (`open`/`close`)
 * ou com `noThisFeature`. A deteção de queda vem nesta segunda forma: o comando aceita o
 * campo, o firmware guarda-o, e não há sensor nenhum por trás -- um interruptor que na
 * dashboard prometia uma vigilância que não existe.
 *
 * Pelo mesmo caminho apareceram cinco medições automáticas que a pulseira suporta e que o
 * catálogo não oferecia: VFC, glicemia, composição sanguínea, stress e o despertar por
 * hipoxia. E o `blood_oxygen_continuous` era um nome errado para este último: a saturação é
 * medida sempre e chega nos blocos, enquanto o interruptor é o alarme. Passa a chamar-se
 * `blood_oxygen_alert`, que é o nome que o hub já dá à mesma coisa nos relógios.
 *
 * As linhas de configuração já gravadas para as duas chaves que desaparecem vão com elas --
 * mas só as das pulseiras. Sem isto a dashboard continuava a mostrá-las como aplicadas, sob um
 * nome que o hub deixou de saber traduzir; e apagando-as por chave, sem dizer de que
 * aparelhos, levava também as dos relógios, onde as duas são reais.
 */
final class VeepooBraceletCapabilities implements Migration
{
    /**
     * As que a MF91 suporta e faltavam: chave, rótulo, secção, e se é para configurar ou
     * para pedir. O «encontrar dispositivo» é o único da segunda espécie -- manda a pulseira
     * vibrar no instante e não guarda estado nenhum.
     */
    private const ADDED = [
        ['hrv_continuous', 'VFC contínua', 'health', 'config'],
        ['blood_sugar_continuous', 'Glicemia contínua', 'health', 'config'],
        ['blood_lipids_continuous', 'Composição sanguínea contínua', 'health', 'config'],
        ['stress_continuous', 'Stress contínuo', 'health', 'config'],
        ['blood_oxygen_alert', 'Alerta de oxigénio no sangue', 'alarms', 'config'],
        ['find_device', 'Encontrar dispositivo', 'settings_system', 'request'],
    ];

    /** O que a pulseira não tem, e o nome que estava errado. */
    private const REMOVED = ['fall_detection', 'blood_oxygen_continuous'];

    public function version(): string
    {
        return '2026_09_09_veepoo_bracelet_capabilities';
    }

    public function up(PDO $pdo): void
    {
        // Numa base ainda por semear não há nada a acertar: o seeder vai escrever o catálogo
        // já corrigido, a partir das mesmas definições em código. E escrever aqui seria pior
        // do que inútil -- é a tabela vazia que diz ao seeder que tem de semear, e enchê-la
        // antes dele deixava a base sem fornecedores, sem modelos e sem empresas.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $insert = $pdo->prepare("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('bracelet', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                section = VALUES(section),
                label = VALUES(label),
                is_configurable = VALUES(is_configurable),
                is_requestable = VALUES(is_requestable)
        ");
        foreach (self::ADDED as [$key, $label, $section, $kind]) {
            $insert->execute([$section, $key, $label, $kind === 'config' ? 1 : 0, $kind === 'request' ? 1 : 0]);
        }

        // A temperatura e o stress passam a poder ser pedidos: ambos respondem com valor.
        $pdo->exec("
            UPDATE capabilities SET is_requestable = 1
            WHERE device_type = 'bracelet' AND capability_key IN ('temperature', 'stress')
        ");

        // Os rótulos das pulseiras ficaram no que foram semeados, e o catálogo em código já
        // mudou. O da temperatura é o que dava um cartão a contradizer-se: dizia «Temperatura
        // corporal» por cima de uma leitura que a pulseira faz na pele.
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

        $placeholders = implode(', ', array_fill(0, count(self::REMOVED), '?'));
        $deletions = [
            // Pelo tipo do aparelho, e não só pela chave: o `device_configurations` é indexado
            // por IMEI e chave, sem coluna de tipo, e as duas chaves existem também nos
            // relógios -- onde são reais. Sem esta junção, apagava a configuração guardada de
            // catorze relógios com deteção de queda.
            "DELETE dc FROM device_configurations dc
               JOIN whitelist w ON w.imei = dc.imei
              WHERE w.device_type = 'bracelet' AND dc.config_key IN ($placeholders)",
            "DELETE FROM model_capabilities WHERE device_type = 'bracelet' AND capability_key IN ($placeholders)",
            "DELETE FROM capabilities WHERE device_type = 'bracelet' AND capability_key IN ($placeholders)",
        ];
        foreach ($deletions as $sql) {
            $pdo->prepare($sql)->execute(self::REMOVED);
        }

        // Dar aos modelos as capacidades novas exige saber quais são pulseiras Veepoo; o
        // seeder faz isso pelo protocolo, e correr aqui a mesma lógica duplicava-a. Basta
        // preencher as lacunas dos modelos que já têm as outras chaves deste protocolo.
        $link = $pdo->prepare("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT model_id, 'bracelet', ?, 1
            FROM model_capabilities
            WHERE device_type = 'bracelet' AND capability_key = 'heart_rate_continuous'
        ");
        foreach (self::ADDED as [$key]) {
            $link->execute([$key]);
        }
    }
}
