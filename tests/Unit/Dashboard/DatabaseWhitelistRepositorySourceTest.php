<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Tests\Support\MysqlDashboardTestCase;

final class DatabaseWhitelistRepositorySourceTest extends MysqlDashboardTestCase
{
    public function testWhitelistStoresNcsAliasInDeviceId(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('ncs-gateway-01', 'Voerka', 'W812', 'ncs', 1001, '', 'gw-001');

        $row = $db->whitelist->get('ncs-gateway-01');
        self::assertIsArray($row);
        self::assertSame('gw-001', $row['device_id'] ?? null);
    }
}
