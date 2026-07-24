<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\DashboardNotificationService;
use Tests\Support\MysqlDashboardTestCase;

final class DashboardNotificationServiceTest extends MysqlDashboardTestCase
{
    private ApiDataAccess $db;
    private DashboardNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $this->service = new DashboardNotificationService($this->db);
    }

    public function testListReturnsItemsAndGlobalUnreadCount(): void
    {
        $this->db->dashboardNotifications->record(
            'device_not_authorized',
            '861265062544868',
            'vivistar-iw',
            'VL16P',
            '',
            'device_not_authorized'
        );

        $result = $this->service->list('limit=20');

        self::assertSame(1, $result['unreadCount']);
        self::assertCount(1, $result['data']);
        self::assertSame('861265062544868', $result['data'][0]['imei']);
    }

    public function testMarkReadValidatesAndUpdatesIds(): void
    {
        self::assertSame(
            'invalid_request',
            $this->service->markRead('{}')['error']['code'] ?? null
        );

        $this->db->dashboardNotifications->record(
            'device_not_authorized',
            '861265062544868',
            'vivistar-iw',
            '',
            '',
            'device_not_authorized'
        );
        $id = (int)$this->db->dashboardNotifications->latest(20)[0]['id'];

        $result = $this->service->markRead(json_encode(['ids' => [$id]], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $result['status']);
        self::assertSame(0, $result['unreadCount']);
    }

    public function testDeleteRemovesNotificationAndRejectsMissingId(): void
    {
        $this->db->dashboardNotifications->record(
            'device_not_authorized',
            '861265062544868',
            'vivistar-iw',
            '',
            '',
            'device_not_authorized'
        );
        $id = (int)$this->db->dashboardNotifications->latest(20)[0]['id'];

        $result = $this->service->delete($id);

        self::assertSame('ok', $result['status']);
        self::assertSame(0, $result['unreadCount']);
        self::assertSame([], $this->db->dashboardNotifications->latest(20));
        self::assertSame(
            'notification_not_found',
            $this->service->delete($id)['error']['code'] ?? null
        );
    }
}
