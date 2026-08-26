<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class SettingsModelsTabFetchTest extends TestCase
{
    /**
     * O catalogo e uma superficie so: tipo de dispositivo, fornecedor, modelo.
     *
     * Os fornecedores nao tem separador proprio porque nao ha nada para lhes fazer -- sao
     * definidos em codigo, e o que se sabe deles e o nome e os modelos que trazem.
     */
    public function testSupplierTabIsMergedIntoTheCatalogueTree(): void
    {
        $settings = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/components/modals/settings.php'
        );
        self::assertIsString($settings);

        self::assertStringNotContainsString('settingsSuppliersPane', $settings);
        self::assertStringNotContainsString('supplierListBody', $settings);
        self::assertStringContainsString('id="modelCatalog"', $settings);
        // O catalogo e o separador de entrada.
        self::assertStringContainsString(
            'class="tab-pane fade show active h-100" id="settingsModelsPane"',
            $settings,
        );
        // Sem filtro de tipo nem de fornecedor: sao os dois niveis da arvore, e um filtro
        // por cima do agrupamento da dois controlos a discordar.
        self::assertStringNotContainsString('modelsDeviceTypeButtons', $settings);
        self::assertStringNotContainsString('modelsSupplierButtons', $settings);
        // Sem paginacao: a arvore vem inteira, e um grupo cortado entre paginas e a pior
        // das duas leituras.
        self::assertStringNotContainsString("pagination_component('settingsModels')", $settings);

        // A coleccao dos fornecedores e so de leitura.
        $routes = file_get_contents(
            dirname(__DIR__, 3) . '/src/Api/Routes/SupplierRoutes.php'
        );
        self::assertIsString($routes);
        self::assertStringContainsString("new ApiRoute('GET', '/api/suppliers'", $routes);
        self::assertStringNotContainsString("'PUT'", $routes);
    }

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
