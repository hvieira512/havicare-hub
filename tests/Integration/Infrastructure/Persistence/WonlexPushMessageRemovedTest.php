<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\WonlexPushMessageRemoved;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/** O relógio Wonlex deixa de ter `push_message`; as Vivistar e as 4P Touch ficam com ele. */
final class WonlexPushMessageRemovedTest extends MysqlDashboardTestCase
{
    public function testOnlyTheWonlexWatchLosesPushMessage(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $insert = $pdo->prepare("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            VALUES (?, 'watch', 'push_message', 1)
        ");
        foreach ([['Wonlex', 'HW20PRO'], ['Vivistar', 'L08 Pro'], ['4P Touch', 'D41']] as [$supplier, $model]) {
            $insert->execute([$this->modelId($pdo, $supplier, $model)]);
        }

        (new WonlexPushMessageRemoved())->up($pdo);
        (new WonlexPushMessageRemoved())->up($pdo);

        self::assertSame(['4P Touch', 'Vivistar'], $pdo->query("
            SELECT DISTINCT s.name FROM model_capabilities mc
            JOIN models m ON m.id = mc.model_id JOIN suppliers s ON s.id = m.supplier_id
            WHERE mc.capability_key = 'push_message' ORDER BY s.name
        ")->fetchAll(PDO::FETCH_COLUMN));
        self::assertGreaterThan(0, (int)$pdo->query(
            'SELECT COUNT(*) FROM model_capabilities WHERE model_id = ' . $this->modelId($pdo, 'Wonlex', 'HW20PRO')
        )->fetchColumn());
    }

    private function modelId(PDO $pdo, string $supplier, string $model): int
    {
        $stmt = $pdo->prepare('
            SELECT m.id FROM models m JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = ? AND m.internal_model = ?
        ');
        $stmt->execute([$supplier, $model]);

        return (int)$stmt->fetchColumn();
    }
}
