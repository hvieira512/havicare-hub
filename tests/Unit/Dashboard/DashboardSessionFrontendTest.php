<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class DashboardSessionFrontendTest extends TestCase
{
    public function testShowingLoginClosesOpenDashboardModalsAndRemovesTheirBackdrops(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/auth/session.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('const closeDashboardOverlays = () => {', $source);
        self::assertStringContainsString(
            'document.querySelectorAll("#dashboardApp .modal.show")',
            $source
        );
        self::assertStringContainsString(
            'window.bootstrap?.Modal?.getInstance(modal)',
            $source
        );
        self::assertStringContainsString(
            '.querySelectorAll(".modal-backdrop, .offcanvas-backdrop")',
            $source
        );
        self::assertStringContainsString(
            'document.body.classList.remove("modal-open")',
            $source
        );
        self::assertStringContainsString(
            'document.body.style.removeProperty("overflow")',
            $source
        );
        self::assertStringContainsString(
            'document.body.style.removeProperty("padding-right")',
            $source
        );
        self::assertStringContainsString('app.hidden = true;', $source);
        self::assertStringContainsString('login.hidden = false;', $source);
        self::assertStringContainsString('login.hidden = true;', $source);
        self::assertStringContainsString('app.hidden = false;', $source);

        $showLoginStart = strpos($source, 'const showLogin = message => {');
        $closeOverlays = strpos($source, 'closeDashboardOverlays();', $showLoginStart ?: 0);
        $hideApp = strpos($source, 'app?.classList.add("d-none")', $showLoginStart ?: 0);

        self::assertIsInt($showLoginStart);
        self::assertIsInt($closeOverlays);
        self::assertIsInt($hideApp);
        self::assertLessThan($hideApp, $closeOverlays);
    }

    public function testAuthenticationViewsRemainHiddenUntilSessionResolution(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/index.php'
        );

        self::assertIsString($template);
        self::assertStringContainsString(
            'id="dashboardLogin" class="dashboard-login row g-0 min-vh-100 d-none" hidden',
            $template
        );
        self::assertStringContainsString(
            '<?= $dashboardApiAuthRequired ? \' hidden\' : \'\' ?>',
            $template
        );
    }
}
