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
            'const visibleSections = sections',
            $source,
        );
        self::assertStringContainsString('const templateSet = new Set(', $source);
        self::assertStringContainsString(
            'state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(',
            $source,
        );
        self::assertStringContainsString(
            'visibleEntries.length === 0',
            $source,
        );
        self::assertStringNotContainsString(
            'bg-white ${!supported ? "opacity-50" : ""}',
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
        self::assertStringContainsString(
            'model.requestableCapabilities.map(String)',
            $source,
        );
        self::assertStringContainsString(
            'data-action="toggleCapabilitySupport"',
            $source,
        );
        self::assertStringContainsString(
            'data-action="toggleCapabilityRequestability"',
            $source,
        );
        self::assertStringContainsString(
            'model.requestableCapabilityKeys.map(String)',
            $source,
        );
        self::assertStringContainsString(
            'capabilityLabelByKey(',
            $source,
        );
        self::assertStringContainsString(
            'state.settingsModal.capabilityCatalog,',
            $source,
        );
        self::assertStringContainsString(
            'Solicitável neste modelo',
            $source,
        );
        self::assertStringContainsString(
            'Apenas receção',
            $source,
        );
        self::assertStringContainsString(
            'body.append("requestableCapabilitiesConfigured", "1");',
            $source,
        );
    }
}
