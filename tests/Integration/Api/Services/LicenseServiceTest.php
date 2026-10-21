<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Services\LicenseService;
use Tests\Support\MysqlDashboardTestCase;

final class LicenseServiceTest extends MysqlDashboardTestCase
{
    /** O `companyId` é chave estrangeira: uma empresa inexistente recusa-se antes da escrita. */
    public function testCreatingALicenseForAnUnknownCompanyIsRefused(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $service = new LicenseService($db);

        $result = $service->create(['companyId' => 999999, 'licenseId' => 4004, 'name' => 'orfa']);

        self::assertSame('company_not_found', $result['error']['code'] ?? null);
    }

    /** O actualizar escreve a mesma chave estrangeira, e recusa pela mesma razão. */
    public function testUpdatingALicenseToAnUnknownCompanyIsRefused(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $companyId = $db->companies->create('hitcare');
        $licenseId = $db->licenses->create($companyId, 5005, 'valida');

        $result = (new LicenseService($db))->update($licenseId, ['companyId' => 999999]);

        self::assertSame('company_not_found', $result['error']['code'] ?? null);
    }

    /** Cada licença da listagem diz se a cloud dos radares está ligada. */
    public function testTheListingSaysWhichLicensesHaveRadarCloudCredentials(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $companyId = $db->companies->create('hitcare');
        $withCloud = $db->licenses->create($companyId, 2103, 'casabrancaresidencial');
        $db->licenses->create($companyId, 2104, 'gerpi1');
        $db->radarCredentials->upsert(
            $withCloud,
            'https://radarconsole.com/prod-api',
            'casabranca',
            'nao-sai-daqui',
            'ql-casabranca',
            'tambem-nao',
        );

        $rows = (new LicenseService($db))->list('limit=1000')['data'];
        $configured = array_column($rows, 'radarCloudConfigured', 'licenseId');

        self::assertTrue($configured[2103]);
        self::assertFalse($configured[2104]);
    }

    /** Cada licença da listagem traz quantos aparelhos a usam. */
    public function testTheListingCountsTheDevicesOnEachLicense(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $companyId = $db->companies->create('hitcare');
        $db->licenses->create($companyId, 2103, 'casabrancaresidencial');
        $db->licenses->create($companyId, 2104, 'gerpi1');
        $db->whitelist->register('351266770073676', '4P Touch', 'Y6M', 'watch', 2103, '', '', 'hitcare');
        $db->whitelist->register('351266770073677', '4P Touch', 'Y6M', 'watch', 2103, '', '', 'hitcare');

        $rows = (new LicenseService($db))->list('limit=1000')['data'];
        $counts = array_column($rows, 'deviceCount', 'licenseId');

        self::assertSame(2, $counts[2103]);
        self::assertSame(0, $counts[2104]);
    }
}
