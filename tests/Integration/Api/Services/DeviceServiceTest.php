<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Request\DeviceAssociationRequest;
use Hub\Api\Request\DeviceWriteRequest;
use Hub\Api\Services\DeviceService;
use Hub\Device\DeviceHubServer;
use Hub\Domain\DeviceMetadata;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Ingress\Mqtt\Qinglanst\QinglanstBridge;
use Hub\Registry\Whitelist;
use Hub\State\ActiveAlarms;
use Hub\State\DeviceStore;
use Hub\State\RedisKeys;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceServiceTest extends MysqlDashboardTestCase
{
    private const RADAR = 'radar-canonical-1';
    private const RADAR_UID = 'radar-topic-uid';

    private DeviceService $service;
    private InMemoryRedisClient $redis;
    /** @var Whitelist&MockObject */
    private Whitelist $whitelist;
    /** @var DeviceHubServer&Stub */
    private DeviceHubServer $hub;
    private ApiDataAccess $db;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = new InMemoryRedisClient();
        // Mock e não stub: prende o argumento com `with()`, que é do mock.
        $this->whitelist = $this->createMock(Whitelist::class);
        $this->hub = $this->createStub(DeviceHubServer::class);
        $this->db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        $this->service = new DeviceService(
            new DeviceStore($this->redis),
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

        $result = $this->service->featureRequests->requestFeature($imei, $payload);

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
        $this->redis->hmset(RedisKeys::device($imei), [
            'imei' => $imei, 'supplier' => 'Wonlex', 'model' => 'Y20', 'protocol' => 'wonlex-json',
            'company' => 'hitcare', 'licenseId' => '1001',
        ]);
        $this->mockModelCapabilities(['reset_device']);
        $client = new ApiAuthContext(7, 'havicare', ApiAuthContext::ROLE_LICENSE_CLIENT, 1001, 1, 1, 'hitcare');

        $result = $this->service->featureRequests->requestFeature($imei, ['capability' => 'reset_device'], $client);

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

        $result = $this->service->associations->associate($imei, new DeviceAssociationRequest($company, $licenseId));

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
        $this->whitelist->method('register')->willReturnCallback(
            function () use (&$writes): void {
                if ($this->redis->hgetall(RedisKeys::device('861265061009877')) !== []) {
                    $writes[] = 'redis';
                }
                $writes[] = 'sql';
            }
        );

        $result = $this->service->create(new DeviceWriteRequest(
            imei: '861265061009877',
            supplier: 'Vivistar',
            model: 'L08 Pro',
            licenseId: null,
        ));

        self::assertSame('ok', $result['status'] ?? null);
        self::assertSame(['redis', 'sql'], $writes);
    }

    /** O dono novo recebe no tópico dele a queda que já estava em curso. */
    public function testAssociatingAnotherOwnerAnnouncesTheActiveFallAgain(): void
    {
        $fall = $this->radarWithAFallAnnounced();

        $result = $fall['service']->associations->associate(self::RADAR, new DeviceAssociationRequest('other', 2002));
        $fall['frame']();

        self::assertSame('ok', $result['status'] ?? null);

        self::assertSame([1001, 2002], array_column($fall['mqtt']->events, 'licenseId'));
    }

    public function testUpdatingTheOwnerAnnouncesTheActiveFallAgain(): void
    {
        $fall = $this->radarWithAFallAnnounced();
        $this->db->models->add($this->db->suppliers->create('Qinglanst'), 'HC2', 'HC2', 'radar');

        $result = $fall['service']->update(self::RADAR, new DeviceWriteRequest(
            supplier: 'Qinglanst',
            model: 'HC2',
            licenseId: 2002,
            deviceId: self::RADAR_UID,
            company: 'other',
        ));
        $fall['frame']();

        self::assertSame('ok', $result['status'] ?? null);
        self::assertSame([1001, 2002], array_column($fall['mqtt']->events, 'licenseId'));
    }

    /** @return array{service: DeviceService, mqtt: RecordingHubMqttBridge, frame: \Closure(): void} */
    private function radarWithAFallAnnounced(): array
    {
        $this->db->licenses->create($this->db->companies->create('other'), 2002, 'other-2002');
        $whitelist = IngressFixtures::whitelist([self::RADAR => IngressFixtures::radar() + ['deviceId' => self::RADAR_UID]]);
        $mqtt = new RecordingHubMqttBridge();
        $bridge = new QinglanstBridge(new FakeMqttSubscriber(), $whitelist, $mqtt, alarms: new ActiveAlarms($this->redis));
        $fallen = array_fill(0, 16, 0);
        [$fallen[0], $fallen[1], $fallen[2], $fallen[13]] = [1, 4, 5, 2];
        $frame = static fn () => $bridge->handleReceivedMessage('radar/1001/' . self::RADAR_UID, json_encode([
            'payload' => ['deviceCode' => self::RADAR_UID, 'position' => base64_encode(implode('', array_map('chr', $fallen)))],
        ], JSON_THROW_ON_ERROR));

        $frame();
        $frame();
        self::assertSame(['fall'], array_column($mqtt->events, 'type'));

        return [
            'service' => new DeviceService(new DeviceStore($this->redis), $whitelist, $this->hub, $this->db),
            'mqtt' => $mqtt,
            'frame' => $frame,
        ];
    }

    private function mockDeviceAccess(string $imei, string $protocol, string $supplier, string $model): void
    {
        $this->whitelist->method('getMetadata')->with($imei)->willReturn(new DeviceMetadata($supplier, $model));
        $supplierId = $this->db->suppliers->create($supplier);
        $this->db->models->add($supplierId, $model, $model, 'watch');
        $this->modelId = (int)($this->db->models->find($supplier, $model)['id'] ?? 0);
        $this->redis->hmset(RedisKeys::device($imei), [
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
