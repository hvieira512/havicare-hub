<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class DeviceRequestCardGroupingTest extends TestCase
{
    public function testDeviceRequestCardsAreGroupedIntoTelemetryAndSystemSections(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/list-detail.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('const TELEMETRY_REQUEST_GROUPS = [', $source);
        self::assertStringContainsString('label: "Telemetria"', $source);
        self::assertStringContainsString('label: "Informação do sistema"', $source);
        self::assertStringContainsString('"firmware_version"', $source);
        self::assertStringContainsString('"device_status"', $source);
        self::assertStringContainsString('renderRequestCardGroup(group, telemetry)', $source);
        self::assertStringContainsString('group.cards.length', $source);
    }
}
