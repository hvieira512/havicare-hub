<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\DashboardDataAccess;
use Hub\Dashboard\DashboardDatabase;
use PHPUnit\Framework\TestCase;

final class DatabaseWhitelistRepositorySourceTest extends TestCase
{
    public function testWhitelistStoresSourceSystemAndSourceDeviceId(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($path));
            $db->whitelist->register('ncs-gateway-01', 'Voerka', 'W812', 'ncs', '1001', '', '', 'voerka', 'gw-001');

            $row = $db->whitelist->get('ncs-gateway-01');
            self::assertIsArray($row);
            self::assertSame('voerka', $row['source_system'] ?? null);
            self::assertSame('gw-001', $row['source_device_id'] ?? null);
        } finally {
            unlink($path);
        }
    }
}
