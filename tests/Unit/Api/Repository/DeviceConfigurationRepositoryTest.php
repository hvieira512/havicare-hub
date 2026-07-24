<?php

namespace Tests\Unit\Api\Repository;

use Hub\Api\Repository\DeviceConfigurationRepository;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceConfigurationRepositoryTest extends MysqlDashboardTestCase
{
    private DeviceConfigurationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new DeviceConfigurationRepository($this->createDashboardDatabase()->pdo());
    }

    public function testSaveDesiredCanonicalizesVivistarAliases(): void
    {
        $this->repository->saveDesired(
            '861265061009822',
            'fallDetection',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP76',
            ['enabled' => false]
        );

        $rows = $this->repository->allForImei('861265061009822');

        self::assertCount(1, $rows);
        self::assertSame('fall_detection', $rows[0]['config_key'] ?? null);
        self::assertSame(['enabled' => false], $rows[0]['desired_payload'] ?? null);
    }

    public function testSaveReportedCanonicalizesVivistarAliases(): void
    {
        $this->repository->saveReported(
            '861265061009822',
            'whitelistSwitch',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP84',
            ['enabled' => true]
        );

        $rows = $this->repository->allForImei('861265061009822');

        self::assertCount(1, $rows);
        self::assertSame('whitelist_enabled', $rows[0]['config_key'] ?? null);
        self::assertSame(['enabled' => true], $rows[0]['reported_payload'] ?? null);
    }

    public function testGenericAlarmClockKeyUsesProtocolNativeIdentity(): void
    {
        $this->repository->saveDesired(
            '861265061009822',
            'alarm_clock',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP85',
            ['items' => []]
        );

        $rows = $this->repository->allForImei('861265061009822');

        self::assertCount(1, $rows);
        self::assertSame('alarm_clock', $rows[0]['config_key'] ?? null);
        self::assertSame('reminders', $rows[0]['native_key'] ?? null);
    }
}
