<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\DashboardDataAccess;
use Tests\Support\MysqlDashboardTestCase;

final class DatabaseWhitelistRepositorySourceTest extends MysqlDashboardTestCase
{
    public function testWhitelistStoresSourceSystemAndSourceDeviceId(): void
    {
        $db = DashboardDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('ncs-gateway-01', 'Voerka', 'W812', 'ncs', '1001', '', '', 'voerka', 'gw-001');

        $row = $db->whitelist->get('ncs-gateway-01');
        self::assertIsArray($row);
        self::assertSame('voerka', $row['source_system'] ?? null);
        self::assertSame('gw-001', $row['source_device_id'] ?? null);
    }
}
