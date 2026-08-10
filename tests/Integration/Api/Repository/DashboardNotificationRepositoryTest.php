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
