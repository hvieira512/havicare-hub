<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Services\DeviceService;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\State\DeviceStoreContract;
use Hub\Device\DeviceHubServer;
use Hub\Domain\DeviceMetadata;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceServiceTest extends MysqlDashboardTestCase
{
    private DeviceService $service;
    /** @var DeviceStoreContract&MockObject */
    private DeviceStoreContract $store;
    /** @var Whitelist&MockObject */
    private Whitelist $whitelist;
    /** @var DeviceHubServer&Stub */
    private DeviceHubServer $hub;
    private ApiDataAccess $db;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock e não stub: as duas prendem o argumento com `with()`, que é do mock.
        $this->store = $this->createMock(DeviceStoreContract::class);

        $this->whitelist = $this->createMock(Whitelist::class);
        $this->hub = $this->createStub(DeviceHubServer::class);
        $this->db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        $this->service = new DeviceService(
            $this->store,
            $this->whitelist,
            $this->hub,
            $this->db
        );
    }

    public function testRequestCapabilityActionForMakeCall(): void
    {
        $imei = '8800000015';
        $capability = 'make_call';
        $phone = '123456789';
        $payload = ['capability' => $capability, 'value' => ['phone' => $phone]];

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $payload);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('CALL', $result['commands'][0]['nativeType']);
    }

    /** Um cliente nunca repõe de fábrica: o relógio volta ao servidor do fornecedor e o hub perde-o. */
    public function testALicenseClientCannotFactoryResetADevice(): void
    {
        $imei = '8800000016';
        $this->whitelist->method('getMetadata')->with($imei)->willReturn(new DeviceMetadata('Wonlex', 'Y20', 'watch', 1001, 'hitcare'));
        $supplierId = $this->db->suppliers->create('Wonlex');
        $this->db->models->add($supplierId, 'Y20', 'Y20', 'watch');
        $this->modelId = (int)($this->db->models->find('Wonlex', 'Y20')['id'] ?? 0);
        $this->store->method('device')->with($imei)->willReturn([
            'imei' => $imei, 'supplier' => 'Wonlex', 'model' => 'Y20', 'protocol' => 'wonlex-json',
            'company' => 'hitcare', 'licenseId' => '1001',
        ]);
        $this->mockModelCapabilities(['reset_device']);
        $client = new ApiAuthContext(7, 'havicare', ApiAuthContext::ROLE_LICENSE_CLIENT, 1001, 1, 1, 'hitcare');

        $result = $this->service->requestFeature($imei, ['capability' => 'reset_device'], $client);

        self::assertSame('forbidden', $result['error']['code'] ?? null);
    }

    public function testPatchAssociationCreatesMissingLicenseForExistingCompany(): void
    {
        $imei = '861265061009844';
        $company = 'hitcare';
        $licenseId = 9999;

        $companyRow = $this->db->companies->findByName($company);
        if ($companyRow === null) {
            $companyId = $this->db->companies->create($company);
            $companyRow = $this->db->companies->findById($companyId);
        }
        self::assertIsArray($companyRow);

        self::assertNull($this->db->licenses->findByCompanyAndLicense((int)$companyRow['id'], $licenseId));

        $this->mockDeviceAccess($imei, 'vivistar-iw', 'Vivistar', 'L08 Pro');

        $result = $this->service->patchAssociation($imei, [
            'company' => $company,
            'licenseId' => (string)$licenseId,
        ]);

        self::assertSame('ok', $result['status'] ?? null);
        self::assertSame($company, $result['association']['company'] ?? null);
        self::assertSame($licenseId, $result['association']['licenseId'] ?? null);

        $created = $this->db->licenses->findByLicenseId($licenseId);
        self::assertNotEmpty($created);
        self::assertNotSame(
            0,
            count(array_filter($created, static fn(array $row): bool => (int)($row['company_id'] ?? 0) === (int)$companyRow['id']))
        );
    }

    /** O Redis escreve-se antes do inventário, para uma falha não deixar inventário sem projecção. */
    public function testCreateWritesTheProjectionBeforeTheInventory(): void
    {
        $supplierId = $this->db->suppliers->create('Vivistar');
        $this->db->models->add($supplierId, 'L08 Pro', 'L08 Pro', 'watch');

        $writes = [];
        $this->store->method('registerDevice')->willReturnCallback(
            static function () use (&$writes): void {
                $writes[] = 'redis';
            }
        );
        $this->whitelist->method('register')->willReturnCallback(
            static function () use (&$writes): void {
                $writes[] = 'sql';
            }
        );

        $result = $this->service->create([
            'imei' => '861265061009877',
            'supplier' => 'Vivistar',
            'model' => 'L08 Pro',
            'licenseId' => '0',
        ]);

        self::assertSame('ok', $result['status'] ?? null);
        self::assertSame(['redis', 'sql'], $writes);
    }

    private function mockDeviceAccess(string $imei, string $protocol, string $supplier, string $model): void
    {
        $this->whitelist->method('getMetadata')->with($imei)->willReturn(new DeviceMetadata($supplier, $model));
        $supplierId = $this->db->suppliers->create($supplier);
        $this->db->models->add($supplierId, $model, $model, 'watch');
        $this->modelId = (int)($this->db->models->find($supplier, $model)['id'] ?? 0);
        $this->store->method('device')->with($imei)->willReturn([
            'imei' => $imei,
            'supplier' => $supplier,
            'model' => $model,
            'protocol' => $protocol,
        ]);
    }

    /** @param list<string> $capabilities */
    private function mockModelCapabilities(array $capabilities): void
    {
        $this->db->modelCapabilities->replaceForModelId($this->modelId, $capabilities);
    }
}
