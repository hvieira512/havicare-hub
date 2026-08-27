<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class SettingsModelsTabFetchTest extends TestCase
{
    /**
     * O catálogo é uma superfície só: tipo de dispositivo, fornecedor, modelo.
     *
     * Os fornecedores não têm separador próprio porque não há nada para lhes fazer -- são
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
        // Sem filtro de tipo nem de fornecedor: são os dois níveis da árvore, e um filtro
        // por cima do agrupamento da dois controlos a discordar.
        self::assertStringNotContainsString('modelsDeviceTypeButtons', $settings);
        self::assertStringNotContainsString('modelsSupplierButtons', $settings);
        // Sem paginação: a árvore vem inteira, e um grupo cortado entre páginas é a pior
        // das duas leituras.
        self::assertStringNotContainsString("pagination_component('settingsModels')", $settings);

        // A colecção dos fornecedores é só de leitura.
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
        self::assertIsString($listSource);
        self::assertIsString($formSource);

        self::assertStringNotContainsString(
            'await callbacks.loadSettingsSuppliersSection();',
            $listSource,
        );
        self::assertStringNotContainsString(
            'state.modelModalSuppliers',
            $formSource,
        );
        self::assertStringContainsString(
            'void refreshNewModelCapabilityTemplate();',
            $formSource,
        );
        self::assertStringContainsString(
            'state.modelModal.enabledCapabilities = [];',
            $formSource,
        );
        // Abrir o formulário é o que vai buscar o template e os fornecedores: até lá, o
        // separador do catálogo não pede nenhum dos dois.
        self::assertStringContainsString(
            'await refreshNewModelCapabilityTemplate();',
            $formSource,
        );
        self::assertStringContainsString(
            'await loadSettingsModelFilters();',
            $formSource,
        );
    }
}
