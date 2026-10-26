<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Registry\Whitelist;
use Tests\Support\MysqlDashboardTestCase;
use Tests\Support\TestInventory;

final class DatabaseWhitelistRepositoryTest extends MysqlDashboardTestCase
{
    public function testWhitelistStoresNcsAliasInDeviceId(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        TestInventory::registerVia($db, 'bea6c3dd8e02', 'Voerka', 'W812', 'ncs', 0, '', 'bea6c3dd8e02');

        $row = $db->whitelist->get('bea6c3dd8e02');
        self::assertIsArray($row);
        self::assertSame('bea6c3dd8e02', $row['device_id'] ?? null);
    }

    /** O âmbito por empresa ignora maiúsculas pela colação, sem `LOWER()`. */
    public function testTheCompanyScopeIgnoresLetterCase(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        TestInventory::registerVia($db, '861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        TestInventory::registerVia($db, '861265061009823', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'havicare');

        foreach (['hitcare', 'HITCARE', 'HitCare'] as $scope) {
            $page = $db->whitelist->listPage([], 1, 50, null, $scope);
            self::assertSame(1, $page['total'], "o âmbito '{$scope}' devia trazer um dispositivo");
            self::assertSame('861265061009822', $page['items'][0]['imei'] ?? null);
        }
    }

    /** O filtro de licença `0` encontra os `NULL` da coluna. */
    public function testTheLegacyLicenseFilterFindsDevicesWithoutLicense(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        TestInventory::registerVia($db, '861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        TestInventory::registerVia($db, '861265061009823', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'hitcare');
        TestInventory::registerVia($db, '861265061009824', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');

        foreach (['none', '0'] as $filter) {
            $page = $db->whitelist->listPage(['licenseId' => $filter], 1, 50);
            self::assertSame(2, $page['total'], "o filtro '{$filter}' devia trazer os dois sem licença");
            self::assertSame(
                ['861265061009823', '861265061009824'],
                array_column($page['items'], 'imei')
            );
        }

        $page = $db->whitelist->listPage(['licenseId' => '1001'], 1, 50);
        self::assertSame(1, $page['total']);
        self::assertSame('861265061009822', $page['items'][0]['imei'] ?? null);
    }

    /** O `license=none` é a ausência de dono: a empresa vem da licença, e sem ela não há nenhuma. */
    public function testTheLicensePairNoneOnlyFindsDevicesWithoutOwner(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        TestInventory::registerVia($db, '861265061009823', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        TestInventory::registerVia($db, '861265061009824', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');

        $page = $db->whitelist->listPage(['license' => ['none']], 1, 50);
        self::assertSame(1, $page['total']);
        self::assertSame('861265061009824', $page['items'][0]['imei'] ?? null);
    }

    public function testDatabaseBackedWhitelistObservesChangesMadeByAnotherProcess(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $whitelist = new Whitelist($db->whitelist, 0);

        TestInventory::registerVia($db, 'canonical-imei', 'Voerka', 'W812', 'ncs', 22, '', 'gateway-uid', 'havicare');

        self::assertTrue($whitelist->isAuthorized('canonical-imei'));
        self::assertSame('havicare', $whitelist->getMetadata('canonical-imei')?->company);
        self::assertSame('canonical-imei', $whitelist->resolve('gateway-uid', 'ncs', 'gateway-uid')['imei'] ?? null);
    }
}
