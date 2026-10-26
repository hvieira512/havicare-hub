<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Request\CompanyWriteRequest;
use Hub\Api\Request\DeviceWriteRequest;
use Hub\Api\Request\LicenseWriteRequest;
use Hub\Api\Request\ModelWriteRequest;
use Hub\Api\Services\CompanyService;
use Hub\Api\Services\DeviceService;
use Hub\Api\Services\LicenseService;
use Hub\Api\Services\ModelService;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Ingress\Tcp\DeviceHubServer;
use Hub\Registry\Whitelist;
use Hub\State\DeviceStore;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\MysqlDashboardTestCase;

/** O dispositivo aponta para o modelo e para a licença: mudar um deles leva-o junto, e apagar um em uso é recusado. */
final class DeviceOwnershipIntegrityTest extends MysqlDashboardTestCase
{
    private const IMEI = '865028000000401';

    private ApiDataAccess $db;
    private Whitelist $whitelist;
    private int $companyId;
    private int $licenseRef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $this->whitelist = new Whitelist($this->db->whitelist);
        $this->companyId = $this->db->companies->create('hitcare');
        $this->licenseRef = $this->db->licenses->create($this->companyId, 1001, 'hitcare-license');
        $this->whitelist->register(self::IMEI, '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
    }

    public function testRenamingACompanyCarriesItsDevices(): void
    {
        (new CompanyService($this->db, $this->whitelist))->update($this->companyId, new CompanyWriteRequest('hitcare-pt'));

        self::assertSame('hitcare-pt', $this->whitelist->getMetadata(self::IMEI)?->company);
    }

    public function testChangingALicenseNumberCarriesItsDevices(): void
    {
        (new LicenseService($this->db, $this->whitelist))->update($this->licenseRef, new LicenseWriteRequest(licenseId: 1002));

        self::assertSame(1002, $this->whitelist->getMetadata(self::IMEI)?->licenseId);
    }

    public function testRenamingAModelCarriesItsDevices(): void
    {
        $model = $this->db->models->find('4P Touch', 'D46');
        self::assertIsArray($model);

        (new ModelService($this->db, $this->whitelist))->update((int)$model['id'], new ModelWriteRequest(
            supplierId: (int)$model['supplier_id'],
            internalModel: 'D46X',
            commercialName: (string)$model['commercial_name'],
            deviceType: 'watch',
        ));

        self::assertSame('D46X', $this->whitelist->getMetadata(self::IMEI)?->model);
    }

    public function testDeletingALicenseInUseIsRefused(): void
    {
        $result = (new LicenseService($this->db, $this->whitelist))->delete($this->licenseRef);

        self::assertSame('license_in_use', $result['error']['code'] ?? null);
        self::assertSame(1001, $this->whitelist->getMetadata(self::IMEI)?->licenseId);
    }

    public function testDeletingACompanyInUseIsRefused(): void
    {
        $result = (new CompanyService($this->db, $this->whitelist))->delete($this->companyId);

        self::assertSame('company_in_use', $result['error']['code'] ?? null);
        self::assertNotNull($this->db->licenses->findById($this->licenseRef));
    }

    public function testDeletingAModelInUseIsRefused(): void
    {
        $model = $this->db->models->find('4P Touch', 'D46');
        self::assertIsArray($model);

        $result = (new ModelService($this->db, $this->whitelist))->delete((int)$model['id']);

        self::assertSame('model_in_use', $result['error']['code'] ?? null);
        self::assertNotNull($this->db->models->findById((int)$model['id']));
    }

    public function testADeviceCannotUseALicenseThatDoesNotExist(): void
    {
        $devices = new DeviceService(
            new DeviceStore(new InMemoryRedisClient()),
            $this->whitelist,
            $this->createStub(DeviceHubServer::class),
            $this->db,
        );

        $result = $devices->create(new DeviceWriteRequest(
            imei: '865028000000402',
            supplier: '4P Touch',
            model: 'D46',
            licenseId: 4242,
            company: 'hitcare',
        ));

        self::assertSame('license_not_found', $result['error']['code'] ?? null);
        self::assertNull($this->whitelist->getMetadata('865028000000402'));
    }
}
