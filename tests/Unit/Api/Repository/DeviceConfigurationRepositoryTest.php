<?php

namespace Tests\Unit\Api\Repository;

use Hub\Api\Repository\DeviceConfigurationRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeviceConfigurationRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DeviceConfigurationRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE device_configurations (
                imei TEXT NOT NULL,
                config_key TEXT NOT NULL,
                protocol TEXT NOT NULL,
                supplier TEXT NOT NULL,
                model TEXT NOT NULL,
                command TEXT NOT NULL,
                desired_payload TEXT NOT NULL,
                reported_payload TEXT NOT NULL,
                last_status TEXT NOT NULL DEFAULT "",
                last_command_id TEXT NOT NULL DEFAULT "",
                desired_updated_at TEXT NOT NULL DEFAULT "",
                applied_at TEXT NOT NULL DEFAULT "",
                reported_at TEXT NOT NULL DEFAULT "",
                PRIMARY KEY (imei, config_key)
            )
        ');

        $this->repository = new DeviceConfigurationRepository($this->pdo);
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
}
