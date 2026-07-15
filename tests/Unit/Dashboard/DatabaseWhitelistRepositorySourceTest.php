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
        $db->whitelist->register('bea6c3dd8e02', 'Voerka', 'W812', 'ncs', 0, '', 'bea6c3dd8e02');

        $row = $db->whitelist->get('bea6c3dd8e02');
        self::assertIsArray($row);
        self::assertSame('bea6c3dd8e02', $row['device_id'] ?? null);
    }
}
