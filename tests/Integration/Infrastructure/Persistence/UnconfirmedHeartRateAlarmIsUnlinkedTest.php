<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\UnconfirmedHeartRateAlarmIsUnlinked;
use Tests\Support\MysqlDashboardTestCase;

/** Os modelos 4P que ficaram com o alarme de frequência cardíaca ligado deixam de o anunciar. */
final class UnconfirmedHeartRateAlarmIsUnlinkedTest extends MysqlDashboardTestCase
{
    public function testTheLinkLeftByTheFirstDeployIsRemoved(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $modelId = (int)$pdo->query("SELECT m.id FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = '4P Touch' LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO model_capabilities (model_id, device_type, capability_key, enabled) VALUES ($modelId, 'watch', 'heart_rate_abnormal', 1)");

        (new UnconfirmedHeartRateAlarmIsUnlinked())->up($pdo);

        self::assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM model_capabilities WHERE capability_key = 'heart_rate_abnormal'")->fetchColumn());
        self::assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM capabilities WHERE device_type = 'watch' AND capability_key = 'heart_rate_abnormal'")->fetchColumn());
    }
}
