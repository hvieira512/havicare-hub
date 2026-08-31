<?php

namespace Tests\Integration\Api\Services;

use Hub\Api\Services\DeviceService;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\DeviceHubServer;
use Hub\Domain\DeviceMetadata;
use Hub\Registry\Whitelist;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceServiceTest extends MysqlDashboardTestCase
{
    private DeviceService $service;
    private DashboardStoreContract $store;
    private Whitelist $whitelist;
    private DeviceHubServer $hub;
    private ApiDataAccess $db;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = $this->createStub(DashboardStoreContract::class);

        $this->whitelist = $this->createStub(Whitelist::class);
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

    public function testRequestCapabilityActionForCenterNumber(): void
    {
        $imei = '8800000015';
        $capability = 'center_number';
        $phone = '987654321';
        $payload = ['capability' => $capability, 'value' => ['phone' => $phone]];

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $payload);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('CENTER', $result['commands'][0]['nativeType']);
    }

    public function testRequestCapabilityActionForPushMessage(): void
    {
        $imei = '8800000015';
        $capability = 'push_message';
        $message = 'Hello World';
        $payload = ['capability' => $capability, 'value' => ['message' => $message]];

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $payload);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('MESSAGE', $result['commands'][0]['nativeType']);
    }

    public function testRequestCapabilityActionForResetDevice(): void
    {
        $imei = '8800000015';
        $capability = 'reset_device';
        $payload = ['capability' => $capability, 'value' => []];

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $payload);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('RESET', $result['commands'][0]['nativeType']);
    }

    public function testRequestCapabilityActionForPowerOff(): void
    {
        $imei = '8800000015';
        $capability = 'power_off';
        $payload = ['capability' => $capability, 'value' => []];

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $payload);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('POWEROFF', $result['commands'][0]['nativeType']);
    }

    public function testRequestCapabilityActionForFindDevice(): void
    {
        $imei = '8800000015';
        $capability = 'find_device';
        $payload = ['capability' => $capability, 'value' => []];

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $payload);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('FIND', $result['commands'][0]['nativeType']);
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

        $before = $this->db->licenses->findByCompanyId((int)$companyRow['id']);
        self::assertSame(
            0,
            count(array_filter($before, static fn(array $row): bool => (int)($row['license_id'] ?? 0) === $licenseId))
        );

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

    /**
     * O Redis é uma projecção do inventário, e por isso é escrito primeiro: uma falha do SQL
     * a seguir deixa lá uma entrada que nada lista, enquanto pela ordem contrária ficava uma
     * linha de inventário sem projecção -- o dispositivo que a dashboard nunca conhece.
     */
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

    private function mockModelCapabilities(array $capabilities): void
    {
        $this->db->modelCapabilities->replaceForModelId($this->modelId, $capabilities);
    }
}
