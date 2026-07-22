<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class SettingsModelsTabFetchTest extends TestCase
{
    public function testModelsTabOpenDoesNotFetchSuppliersOrTemplateEagerly(): void
    {
        $listSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/settings/models/list.js'
        );
        $formSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/settings/models/form.js'
        );
        $capabilitiesSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/settings/capabilities.js'
        );

        self::assertIsString($listSource);
        self::assertIsString($formSource);
        self::assertIsString($capabilitiesSource);

        self::assertStringNotContainsString(
            'await callbacks.loadSettingsSuppliersSection();',
            $listSource,
        );
        self::assertStringNotContainsString(
            'state.modelModalSuppliers',
            $formSource,
        );
        self::assertStringContainsString(
            'void callbacks.refreshNewModelCapabilityTemplate?.();',
            $formSource,
        );
        self::assertStringContainsString(
            'state.modelModal.enabledCapabilities = [];',
            $formSource,
        );
        self::assertStringContainsString(
            'await refreshNewModelCapabilityTemplate();',
            $capabilitiesSource,
        );
        self::assertStringContainsString(
            'await loadSettingsModelFilters();',
            $capabilitiesSource,
        );
    }
}
