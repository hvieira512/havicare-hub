<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Registry\Whitelist;
use Hub\Registry\WhitelistFileImporter;
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

    public function testDatabaseBackedWhitelistDoesNotImplicitlyImportOrRewriteLegacyFile(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $path = sys_get_temp_dir() . '/hub-legacy-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        $legacyContents = json_encode([
            'legacy-device' => ['supplier' => 'Legacy', 'model' => 'Legacy'],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($path, $legacyContents);

        try {
            $whitelist = new Whitelist($path, $db->whitelist);
            self::assertFalse($whitelist->isAuthorized('legacy-device'));

            $whitelist->register('861265061009822', 'Vivistar', 'L08 Pro');
            self::assertSame($legacyContents, file_get_contents($path));
            self::assertNotNull($db->whitelist->get('861265061009822'));
        } finally {
            @unlink($path);
        }
    }

    public function testDatabaseBackedWhitelistObservesChangesMadeByAnotherProcess(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $whitelist = new Whitelist(null, $db->whitelist, 0);

        $db->whitelist->register('canonical-imei', 'Voerka', 'W812', 'ncs', 22, '', 'gateway-uid', 'havicare');

        self::assertTrue($whitelist->isAuthorized('canonical-imei'));
        self::assertSame('havicare', $whitelist->getMetadata('canonical-imei')['company'] ?? null);
        self::assertSame('canonical-imei', $whitelist->resolve('gateway-uid', 'ncs', 'gateway-uid')['imei'] ?? null);
    }

    public function testLegacyWhitelistImportIsExplicit(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $path = sys_get_temp_dir() . '/hub-explicit-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode([
            'canonical-imei' => [
                'supplier' => 'Voerka',
                'model' => 'W812',
                'deviceType' => 'ncs',
                'licenseId' => 22,
                'company' => 'havicare',
                'deviceId' => 'gateway-uid',
            ],
            'invalid' => ['supplier' => ''],
        ], JSON_THROW_ON_ERROR));

        try {
            $result = (new WhitelistFileImporter($db->whitelist))->import($path);
            self::assertSame(['imported' => 1, 'skipped' => 1], $result);
            self::assertSame('gateway-uid', $db->whitelist->get('canonical-imei')['device_id'] ?? null);
        } finally {
            @unlink($path);
        }
    }
}
