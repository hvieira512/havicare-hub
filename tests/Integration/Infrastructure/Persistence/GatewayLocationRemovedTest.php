<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\GatewayLocationRemoved;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/** O gateway deixa de ter `location`; os relógios ficam com ela. */
final class GatewayLocationRemovedTest extends MysqlDashboardTestCase
{
    public function testOnlyTheGatewayLosesLocation(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, m.device_type, 'location', 1 FROM models m WHERE m.device_type IN ('gateway', 'watch')
        ");

        (new GatewayLocationRemoved())->up($pdo);
        (new GatewayLocationRemoved())->up($pdo);

        self::assertSame(['watch'], $pdo->query(
            "SELECT DISTINCT device_type FROM model_capabilities WHERE capability_key = 'location'"
        )->fetchAll(PDO::FETCH_COLUMN));
    }
}
