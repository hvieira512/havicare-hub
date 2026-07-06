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
        $filtersSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/settings/models/filters.js'
        );
        $formSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/settings/models/form.js'
        );
        $settingsSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/settings/index.js'
        );

        self::assertIsString($listSource);
        self::assertIsString($filtersSource);
        self::assertIsString($formSource);
        self::assertIsString($settingsSource);

        self::assertStringNotContainsString(
            'await callbacks.loadSettingsSuppliersSection();',
            $listSource,
        );
        self::assertStringNotContainsString(
            'state.modelModalSuppliers',
            $filtersSource,
        );
        self::assertStringContainsString(
            'await refreshNewModelCapabilityTemplate();',
            $settingsSource,
        );
        self::assertStringContainsString(
            'await loadSettingsModelFilters();',
            $settingsSource,
        );
    }
}
