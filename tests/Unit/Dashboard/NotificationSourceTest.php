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
        // A raiz de composicao liga o sino ao assistente, e e o assistente que sabe o que
        // fazer com o aviso: as afirmacoes seguintes atravessam os dois.
        $bootstrap = file_get_contents($root . '/src/Dashboard/dashboard/app.js')
            . file_get_contents($root . '/src/Dashboard/dashboard/devices/create-wizard.js');

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
        // O aviso de um dispositivo não autorizado abre o assistente já com o que o hub
        // reportou -- protocolo para o modelo, modelo para o tipo, e a identidade -- para
        // não se escrever à mão o que ele acabou de dizer.
        self::assertStringContainsString('async function openWizard(source = "")', $bootstrap);
        self::assertStringContainsString('function seedFromNotification(source, tree = [])', $bootstrap);
        self::assertStringContainsString(
            'ownerFromLicense(notification?.licenseId, tree, notification?.company)',
            $bootstrap
        );
        self::assertStringContainsString('String(model.protocol || "") === protocol', $bootstrap);
        self::assertStringContainsString('type: modelDeviceType(detected)', $bootstrap);
        self::assertStringContainsString('openAddDevice: openWizard', $bootstrap);
        self::assertStringContainsString('initNotifications({', $bootstrap);
    }
}
