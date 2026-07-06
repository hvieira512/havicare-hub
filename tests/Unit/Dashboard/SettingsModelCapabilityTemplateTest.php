<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class SettingsModelCapabilityTemplateTest extends TestCase
{
    public function testModelCapabilitiesAreBoundToSupplierTemplateInTheSettingsEditor(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/settings/index.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(',
            $source,
        );
        self::assertStringContainsString('templateSet.has(key)', $source);
        self::assertStringContainsString('templateKeys.length > 0', $source);
        self::assertStringContainsString('enabled.has(entry.key)', $source);
    }
}
