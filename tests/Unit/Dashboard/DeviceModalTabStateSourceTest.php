<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class DeviceModalTabStateSourceTest extends TestCase
{
    public function testEditingAnotherDevicePreservesTheVisibleConfigurationTab(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/core/bootstrap.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('function activeDeviceModalTab()', $source);
        self::assertMatchesRegularExpression(
            '/async function editDevice\\(imei, supplier, model\\).*?'
            . 'const activeTab = activeDeviceModalTab\\(\\);.*?'
            . 'state\\.deviceModal = \\{.*?'
            . 'activeTab,/s',
            $source,
        );
    }

    public function testAddDeviceResetsBothBootstrapTabButtons(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/core/bootstrap.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'els.deviceConfigTabBtn?.classList.remove("active");',
            $source,
        );
        self::assertStringContainsString(
            'els.deviceConfigTabBtn?.setAttribute("aria-selected", "false");',
            $source,
        );
        self::assertStringContainsString(
            'els.deviceGeneralTabBtn?.setAttribute("aria-selected", "true");',
            $source,
        );
    }
}
