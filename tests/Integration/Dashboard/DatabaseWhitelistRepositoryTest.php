<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Registry\Whitelist;
use Hub\Registry\WhitelistFileImporter;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\MysqlDashboardTestCase;

final class DatabaseWhitelistRepositoryTest extends MysqlDashboardTestCase
{
    public function testWhitelistStoresNcsAliasInDeviceId(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('bea6c3dd8e02', 'Voerka', 'W812', 'ncs', 0, '', 'bea6c3dd8e02');

        $row = $db->whitelist->get('bea6c3dd8e02');
        self::assertIsArray($row);
        self::assertSame('bea6c3dd8e02', $row['device_id'] ?? null);
    }

    public function testDatabaseBackedWhitelistDoesNotImplicitlyImportOrRewriteLegacyFile(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $path = IngressFixtures::whitelistPath([
            'legacy-device' => ['supplier' => 'Legacy', 'model' => 'Legacy'],
        ]);
        $legacyContents = (string)file_get_contents($path);

        $whitelist = new Whitelist($path, $db->whitelist);
        self::assertFalse($whitelist->isAuthorized('legacy-device'));

        $whitelist->register('861265061009822', 'Vivistar', 'L08 Pro');
        self::assertSame($legacyContents, file_get_contents($path));
        self::assertNotNull($db->whitelist->get('861265061009822'));
    }

    public function testDatabaseBackedWhitelistObservesChangesMadeByAnotherProcess(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $whitelist = new Whitelist(null, $db->whitelist, 0);

        $db->whitelist->register('canonical-imei', 'Voerka', 'W812', 'ncs', 22, '', 'gateway-uid', 'havicare');

        self::assertTrue($whitelist->isAuthorized('canonical-imei'));
        self::assertSame('havicare', $whitelist->getMetadata('canonical-imei')?->company);
        self::assertSame('canonical-imei', $whitelist->resolve('gateway-uid', 'ncs', 'gateway-uid')['imei'] ?? null);
    }

    public function testLegacyWhitelistImportIsExplicit(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $path = IngressFixtures::whitelistPath([
            'canonical-imei' => [
                'supplier' => 'Voerka',
                'model' => 'W812',
                'deviceType' => 'ncs',
                'licenseId' => 22,
                'company' => 'havicare',
                'deviceId' => 'gateway-uid',
            ],
            'invalid' => ['supplier' => ''],
        ]);

        $result = (new WhitelistFileImporter($db->whitelist))->import($path);
        self::assertSame(['imported' => 1, 'skipped' => 1], $result);
        self::assertSame('gateway-uid', $db->whitelist->get('canonical-imei')['device_id'] ?? null);
    }
}
