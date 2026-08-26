<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * As capacidades do radar passam a nomear o que se mede.
 *
 * Saem `positions` e `vitals`, que eram os envelopes do fabricante. Entram `heart_rate`,
 * `breath_rate`, `sleep_state`, `presence` e `posture`, mais os três alarmes.
 *
 * Três passos, e a ordem importa.
 *
 * O `syncCapabilities` cria as linhas novas em `capabilities` -- só faz upsert, nunca
 * apaga, por isso as velhas têm de sair à mão a seguir. As duas que saem levam primeiro as
 * linhas de `model_capabilities` que lhes apontam, senão ficam órfãs a apontar para nada.
 *
 * Depois liga as novas a todos os modelos de radar. Sem isto as capacidades existem e
 * nenhum radar as tem, portanto a dashboard não desenha cartão nenhum. `INSERT IGNORE` e
 * sem `UPDATE`: se alguém desligar uma à mão um dia, fica desligada.
 */
final class Version2026082804RadarCapabilityVocabulary implements Migration
{
    private const REMOVED = ['positions', 'vitals'];

    public function version(): string
    {
        return '2026082804_radar_capability_vocabulary';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->syncCapabilities($pdo);

        $placeholders = implode(',', array_fill(0, count(self::REMOVED), '?'));

        $pdo->prepare("
            DELETE mc
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE c.device_type = 'radar' AND c.capability_key IN ({$placeholders})
        ")->execute(self::REMOVED);

        $pdo->prepare("
            DELETE FROM capabilities
            WHERE device_type = 'radar' AND capability_key IN ({$placeholders})
        ")->execute(self::REMOVED);

        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled)
            SELECT m.id, c.id, 1
            FROM models m
            JOIN capabilities c ON c.device_type = m.device_type
            WHERE m.device_type = 'radar'
        ");
    }
}
