<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\LicenseService;
use Tests\Support\MysqlDashboardTestCase;

final class LicenseServiceTest extends MysqlDashboardTestCase
{
    /**
     * O `companyId` é uma chave estrangeira, e uma empresa que não existe tem de ser recusada
     * antes da escrita: o erro do PDO sobe como 500 e a especificação não o declara.
     */
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

    /**
     * Quantos aparelhos usam a licença é o que se quer saber antes de a apagar, e tem de vir
     * com a listagem: contado do lado do ecrã, quem abrisse as Definições sem ter passado
     * pela lista de dispositivos via zero em todas.
     */
    public function testTheListingCountsTheDevicesOnEachLicense(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $companyId = $db->companies->create('hitcare');
        $db->licenses->create($companyId, 2103, 'casabrancaresidencial');
        $db->licenses->create($companyId, 2104, 'gerpi1');
        $db->whitelist->register('351266770073676', '4P Touch', 'Y6M', 'watch', 2103, '', '', 'hitcare');
        $db->whitelist->register('351266770073677', '4P Touch', 'Y6M', 'watch', 2103, '', '', 'hitcare');

        $rows = (new LicenseService($db))->list('limit=1000')['data'];
        $counts = array_column($rows, 'device_count', 'license_id');

        self::assertSame(2, (int)$counts[2103]);
        self::assertSame(0, (int)$counts[2104]);
    }
}
