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

    public function testFourPTouchSettingsModalUsesNativeEditors(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/config.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('if (input === "requestAction")', $source);
        self::assertStringContainsString('if (input === "soundProfile")', $source);
        self::assertStringContainsString('function requestActionInput(entry)', $source);
        self::assertStringContainsString('function soundProfileInput(desired)', $source);
        self::assertStringContainsString('name="soundProfile"', $source);
        self::assertStringContainsString('data-config-field="mode"', $source);
        self::assertStringContainsString('role="radiogroup"', $source);
        self::assertStringContainsString('Vibração e toque', $source);
        self::assertStringContainsString('Só toque', $source);
        self::assertStringContainsString('Só vibração', $source);
        self::assertStringContainsString('Silêncio', $source);
        self::assertStringContainsString('sem parâmetros', $source);
        self::assertStringContainsString('4 modos', $source);
    }
}
