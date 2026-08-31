<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Repository\CapabilityDiscoveryRepository;
use Hub\Api\Services\CapabilityDiscoveryService;
use Hub\Api\Services\DeviceService;
use Tests\Support\MysqlDashboardTestCase;

final class CapabilityDiscoveryApiTest extends MysqlDashboardTestCase
{
    private string $repoDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoDir = sys_get_temp_dir() . '/hub-capability-discovery-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repoDir)) {
            foreach (glob($this->repoDir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->repoDir);
        }
        parent::tearDown();
    }

    public function testPreviewBuildsDraftFromDeviceEvidenceAndApplyPersistsIt(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate', 'location']);

        $device = $this->createMock(DeviceService::class);
        $device->expects(self::once())
            ->method('show')
            ->willReturn([
                'device' => ['imei' => '861265061009822', 'online' => true],
                'model' => [
                    'supplier' => 'Vivistar',
                    'internalModel' => 'L08 Pro',
                    'commercialName' => 'L08 Pro',
                    'deviceType' => 'watch',
                ],
                'enabledCapabilityKeys' => ['heart_rate', 'blood_oxygen', 'location'],
            ]);

        $service = new CapabilityDiscoveryService(
            $db,
            $device,
            new CapabilityDiscoveryRepository($this->repoDir),
        );

        $preview = $service->preview([
            'imei' => '861265061009822',
            'modelId' => (int)$model['id'],
        ], null, 'http://localhost:8081');

        self::assertSame('draft', $preview['status'] ?? null);
        self::assertSame((int)$model['id'], $preview['model']['id'] ?? null);
        self::assertSame(['blood_oxygen'], $preview['changes']['add'] ?? []);
        self::assertSame([], $preview['changes']['remove'] ?? []);
        self::assertNotEmpty($preview['evidence'] ?? []);

        $apply = $service->apply((string)$preview['id']);

        self::assertSame('applied', $apply['status'] ?? null);
        self::assertSame(
            ['blood_oxygen', 'heart_rate', 'location'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
    }
}
