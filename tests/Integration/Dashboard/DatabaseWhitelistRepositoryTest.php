<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
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

    /**
     * O âmbito por empresa ignora maiúsculas pela colação `utf8mb4_unicode_ci`, sem `LOWER()`,
     * que impediria o uso do `idx_whitelist_company`.
     */
    public function testTheCompanyScopeIgnoresLetterCase(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->whitelist->register('861265061009823', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'havicare');

        foreach (['hitcare', 'HITCARE', 'HitCare'] as $scope) {
            $page = $db->whitelist->listPage([], 1, 50, null, $scope);
            self::assertSame(1, $page['total'], "o âmbito '{$scope}' devia trazer um dispositivo");
            self::assertSame('861265061009822', $page['items'][0]['imei'] ?? null);
        }
    }

    /**
     * A sentinela `0` é «sem licença» em memória e `NULL` na coluna: o filtro converte-a na
     * mesma fronteira em que a escrita a converte.
     */
    public function testTheLegacyLicenseFilterFindsDevicesWithoutLicense(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->whitelist->register('861265061009823', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'hitcare');
        $db->whitelist->register('861265061009824', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');

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

    /** Já o `license=none` é a ausência de dono, e não só a de licença. */
    public function testTheLicensePairNoneOnlyFindsDevicesWithoutOwner(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('861265061009823', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'hitcare');
        $db->whitelist->register('861265061009824', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');

        $page = $db->whitelist->listPage(['license' => ['none']], 1, 50);
        self::assertSame(1, $page['total']);
        self::assertSame('861265061009824', $page['items'][0]['imei'] ?? null);
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
