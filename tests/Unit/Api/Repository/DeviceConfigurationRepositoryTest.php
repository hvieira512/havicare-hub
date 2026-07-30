<?php

namespace Tests\Unit\Api\Repository;

use Hub\Api\Repository\DeviceConfigurationRepository;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceConfigurationRepositoryTest extends MysqlDashboardTestCase
{
    private DeviceConfigurationRepository $repository;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->createDashboardDatabase()->pdo();
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

    public function testSaveDesiredCanonicalizesFourPTouchContactSlots(): void
    {
        $this->repository->saveDesired(
            '868017032159118',
            'sosNumber1',
            'four-p-touch',
            '4P Touch',
            'D46',
            'SOS1',
            ['phone' => '123456789']
        );

        $rows = $this->repository->allForImei('868017032159118');

        self::assertCount(1, $rows);
        self::assertSame('sos_contacts', $rows[0]['config_key'] ?? null);
        self::assertSame('sosNumber1', $rows[0]['native_key'] ?? null);
    }

    public function testAllForImeiIgnoresOlderDuplicateNativeSlot(): void
    {
        $insert = $this->pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, supplier, model, command,
                desired_payload, reported_payload, last_status, last_command_id,
                desired_updated_at, reported_at, applied_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([
            '868017032159118', 'sos_contacts', 'sosNumber1', 'four-p-touch', '4P Touch', 'D46', 'SOS1',
            '{"phone":"123456789"}', '{}', 'acked', 'old', '2026-07-01T08:00:00Z', '', '2026-07-01T08:00:01Z',
        ]);
        $insert->execute([
            '868017032159118', 'sosNumber1', 'sosNumber1', 'four-p-touch', '4P Touch', 'D46', 'SOS1',
            '{"phone":""}', '{}', 'acked', 'new', '2026-07-02T08:00:00Z', '', '2026-07-02T08:00:01Z',
        ]);

        $rows = $this->repository->allForImei('868017032159118');

        self::assertCount(1, $rows);
        self::assertSame(['phone' => ''], $rows[0]['desired_payload'] ?? null);
        self::assertSame('new', $rows[0]['last_command_id'] ?? null);
    }

    public function testAllForImeiPreservesDifferentGenericConfigurationsSharingNativeKey(): void
    {
        $insert = $this->pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, supplier, model, command,
                desired_payload, reported_payload, last_status, last_command_id,
                desired_updated_at, reported_at, applied_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([
            '868705080304889', 'phonebook', 'familyNumber', 'wonlex-json', 'Wonlex', 'HW20PRO',
            'familyNumber', '{"contacts":[{"name":"Hugo","phone":"+351938854803"}]}', '{}',
            'acked', 'phonebook-command', '2026-07-30T15:44:10Z', '', '2026-07-30T15:44:10Z',
        ]);
        $insert->execute([
            '868705080304889', 'sos_contacts', 'familyNumber', 'wonlex-json', 'Wonlex', 'HW20PRO',
            'familyNumber', '{"contacts":[{"name":"Hugo","phone":"938854803","areaCode":"351"}]}', '{}',
            'acked', 'sos-command', '2026-07-30T15:44:15Z', '', '2026-07-30T15:44:16Z',
        ]);

        $rows = $this->repository->allForImei('868705080304889');

        self::assertCount(2, $rows);
        self::assertSame(
            ['phonebook', 'sos_contacts'],
            array_column($rows, 'config_key')
        );
    }
}
