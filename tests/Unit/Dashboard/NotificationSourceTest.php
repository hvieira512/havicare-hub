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
        // Adicionar um dispositivo passou a ser o assistente, num modal proprio; a
        // ligacao que arranca as notificacoes continua no bootstrap, mas o que o assistente
        // faz com o aviso mudou-se para o modulo dos seus handlers.
        $bootstrap = file_get_contents($root . '/src/Dashboard/dashboard/core/bootstrap.js')
            . file_get_contents($root . '/src/Dashboard/dashboard/core/handlers/create-wizard.js');

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
        // O aviso de um dispositivo nao autorizado abre o assistente ja com o que o hub
        // reportou -- protocolo para o modelo, modelo para o tipo, e a identidade -- para
        // nao se escrever a mao o que ele acabou de dizer.
        self::assertStringContainsString('async function openWizard(source = "")', $bootstrap);
        self::assertStringContainsString('function seedFromNotification(source)', $bootstrap);
        self::assertStringContainsString('String(model.protocol || "") === protocol', $bootstrap);
        self::assertStringContainsString('type: modelDeviceType(detected)', $bootstrap);
        self::assertStringContainsString('openAddDevice: openWizard', $bootstrap);
        self::assertStringContainsString('initNotifications({', $bootstrap);
    }
}
