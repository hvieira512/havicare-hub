<?php

namespace Tests\Unit\Api\Services;

use Hub\Api\Services\DeviceService;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DashboardStoreContract;
use Hub\DeviceHubServer;
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
            null,
            $this->db
        );
    }

    public function testRequestCapabilityActionForMakeCall(): void
    {
        $imei = '8800000015';
        $capability = 'make_call';
        $phone = '123456789';
        $body = json_encode(['capability' => $capability, 'value' => ['phone' => $phone]]);

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $body);

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
        $body = json_encode(['capability' => $capability, 'value' => ['phone' => $phone]]);

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $body);

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
        $body = json_encode(['capability' => $capability, 'value' => ['message' => $message]]);

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $body);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('MESSAGE', $result['commands'][0]['nativeType']);
    }

    public function testRequestCapabilityActionForResetDevice(): void
    {
        $imei = '8800000015';
        $capability = 'reset_device';
        $body = json_encode(['capability' => $capability, 'value' => []]);

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $body);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('RESET', $result['commands'][0]['nativeType']);
    }

    public function testRequestCapabilityActionForPowerOff(): void
    {
        $imei = '8800000015';
        $capability = 'power_off';
        $body = json_encode(['capability' => $capability, 'value' => []]);

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $body);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('POWEROFF', $result['commands'][0]['nativeType']);
    }

    public function testRequestCapabilityActionForFindDevice(): void
    {
        $imei = '8800000015';
        $capability = 'find_device';
        $body = json_encode(['capability' => $capability, 'value' => []]);

        $this->mockDeviceAccess($imei, 'four-p-touch', '4P Touch', '4P-TOUCH');
        $this->mockModelCapabilities([$capability]);
        $this->hub->method('submitDownlink')->willReturn('sent');

        $result = $this->service->requestFeature($imei, $body);

        self::assertSame('waiting', $result['status']);
        self::assertCount(1, $result['commands']);
        self::assertSame('waiting', $result['commands'][0]['status']);
        self::assertSame('FIND', $result['commands'][0]['nativeType']);
    }

    private function mockDeviceAccess(string $imei, string $protocol, string $supplier, string $model): void
    {
        $this->whitelist->method('getMetadata')->with($imei)->willReturn([
            'imei' => $imei,
            'supplier' => $supplier,
            'model' => $model,
            'protocol' => $protocol,
        ]);
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
