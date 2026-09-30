<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\LicenseService;
use Tests\Support\MysqlDashboardTestCase;

final class LicenseServiceTest extends MysqlDashboardTestCase
{
    /**
     * A linha de cada licença diz se a cloud dos radares já está ligada, e a listagem é o
     * único sítio de onde isso pode vir sem uma chamada por linha.
     */
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
        $configured = array_column($rows, 'radar_cloud_configured', 'license_id');

        self::assertSame(1, (int)$configured[2103]);
        self::assertSame(0, (int)$configured[2104]);
    }
}
