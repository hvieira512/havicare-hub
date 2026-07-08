<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class SettingsModelCapabilityTemplateTest extends TestCase
{
    public function testModelCapabilitiesAreBoundToSupplierTemplateInTheSettingsEditor(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/settings/capabilities.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(',
            $source,
        );
        self::assertStringContainsString('const templateSet = new Set(', $source);
        self::assertStringContainsString(
            'state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(',
            $source,
        );
        self::assertStringContainsString(
            '.filter((key) => (templateSet.size === 0 ? true : templateSet.has(key)));',
            $source,
        );
        self::assertStringContainsString(
            'state.settingsModal.capabilityModelTemplateKeys =',
            $source,
        );
        self::assertStringContainsString(
            'tmpl.enabledCapabilities.map(String);',
            $source,
        );
    }
}
