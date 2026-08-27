<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Repository;

use Hub\Api\Repository\DashboardNotificationRepository;
use Tests\Support\MysqlDashboardTestCase;

final class DashboardNotificationRepositoryTest extends MysqlDashboardTestCase
{
    private DashboardNotificationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new DashboardNotificationRepository(
            $this->createDashboardDatabase()->pdo()
        );
    }

    public function testRepeatedAttemptsAreGroupedAndBecomeUnreadAgain(): void
    {
        $this->repository->record(
            'device_not_authorized',
            '861265062544868',
            'vivistar-iw',
            'VL16P',
            'IW',
            'device_not_authorized'
        );

        $first = $this->repository->latest(20);
        self::assertCount(1, $first);
        self::assertSame(1, $first[0]['occurrenceCount']);
        self::assertSame(1, $this->repository->unreadCount());

        $this->repository->markRead([(int)$first[0]['id']]);
        self::assertSame(0, $this->repository->unreadCount());
        self::assertNotNull($this->repository->latest(20)[0]['readAt']);

        $this->repository->record(
            'device_not_authorized',
            '861265062544868',
            'vivistar-iw',
            'VL16P updated',
            'IW',
            'device_not_authorized'
        );

        $latest = $this->repository->latest(20);
        self::assertCount(1, $latest);
        self::assertSame(2, $latest[0]['occurrenceCount']);
        self::assertSame('VL16P updated', $latest[0]['model']);
        self::assertNull($latest[0]['readAt']);
        self::assertSame(1, $this->repository->unreadCount());
    }

    public function testDifferentProtocolsProduceSeparateNotifications(): void
    {
        foreach (['vivistar-iw', 'four-p-touch'] as $protocol) {
            $this->repository->record(
                'device_not_authorized',
                '861265062544868',
                $protocol,
                '',
                '',
                'device_not_authorized'
            );
        }

        self::assertCount(2, $this->repository->latest(20));
        self::assertSame(2, $this->repository->unreadCount());
    }

    public function testOwnerIsStoredWholeOrNotAtAll(): void
    {
        // Quem se identifica por IMEI ou MAC não diz de quem é: as duas colunas ficam a NULL.
        $this->repository->record(
            'device_not_authorized',
            '861265062544868',
            'vivistar-iw',
            'VL16P',
            'IW',
            'device_not_authorized'
        );

        // O tópico do radar traz a licença, e a empresa vem com ela.
        $this->repository->record(
            'device_not_authorized',
            '9D8A3204F853',
            'qinglanst-radar',
            '',
            '9D8A3204F853',
            'device_not_authorized',
            1001,
            'hitcare'
        );

        $byImei = [];
        foreach ($this->repository->latest(20) as $notification) {
            $byImei[$notification['imei']] = $notification;
        }

        self::assertSame(0, $byImei['861265062544868']['licenseId']);
        self::assertNull($byImei['861265062544868']['company']);
        self::assertSame(1001, $byImei['9D8A3204F853']['licenseId']);
        self::assertSame('hitcare', $byImei['9D8A3204F853']['company']);

        $rows = $this->createDashboardDatabase()->pdo()
            ->query('SELECT imei, license_id, company FROM dashboard_notifications ORDER BY imei')
            ->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            self::assertSame(
                $row['license_id'] === null,
                $row['company'] === null,
                "A empresa e a licença de {$row['imei']} têm de estar ambas presentes ou ambas ausentes"
            );
        }
    }

    public function testDeleteRemovesNotification(): void
    {
        $this->repository->record(
            'device_not_authorized',
            '861265062544868',
            'vivistar-iw',
            '',
            '',
            'device_not_authorized'
        );
        $id = (int)$this->repository->latest(20)[0]['id'];

        self::assertTrue($this->repository->delete($id));
        self::assertFalse($this->repository->delete($id));
        self::assertSame([], $this->repository->latest(20));
        self::assertSame(0, $this->repository->unreadCount());
    }
}
