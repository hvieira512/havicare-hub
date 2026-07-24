<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class NotificationSourceTest extends TestCase
{
    public function testNavbarAndDashboardWireUnauthorizedDeviceNotifications(): void
    {
        $root = dirname(__DIR__, 3);
        $page = file_get_contents($root . '/src/Dashboard/index.php');
        $notifications = file_get_contents($root . '/src/Dashboard/dashboard/notifications.js');
        $bootstrap = file_get_contents($root . '/src/Dashboard/dashboard/core/bootstrap.js');

        self::assertIsString($page);
        self::assertIsString($notifications);
        self::assertIsString($bootstrap);

        self::assertStringContainsString('id="dashboardNotificationsDropdown"', $page);
        self::assertStringContainsString('id="dashboardNotificationsBadge"', $page);
        self::assertStringContainsString('data-bs-auto-close="outside"', $page);
        self::assertStringContainsString('getNotifications(20)', $notifications);
        self::assertStringContainsString('markNotificationsRead(unreadIds)', $notifications);
        self::assertStringContainsString('deleteNotification(id)', $notifications);
        self::assertStringContainsString('data-notification-id=', $notifications);
        self::assertStringContainsString('data-notification-dismiss=', $notifications);
        self::assertStringContainsString('void addDevice(notification)', $notifications);
        self::assertStringContainsString('async function openAddDevice(source = "")', $bootstrap);
        self::assertStringContainsString('String(model.protocol || "") === protocol', $bootstrap);
        self::assertStringContainsString('renderDeviceTypeSelector(detectedDeviceType)', $bootstrap);
        self::assertStringContainsString('detectedSupplier', $bootstrap);
        self::assertStringContainsString('initNotifications({', $bootstrap);
    }
}
