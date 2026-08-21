<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class DeviceModalTabStateSourceTest extends TestCase
{
    public function testEditingAnotherDevicePreservesTheVisibleConfigurationTab(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/device-modal.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('function activeDeviceModalTab()', $source);
        self::assertMatchesRegularExpression(
            '/(?:export )?async function editDevice\\(imei, supplier, model\\).*?'
            . 'const activeTab = activeDeviceModalTab\\(\\);.*?'
            . 'state\\.deviceModal = \\{.*?'
            . 'activeTab,/s',
            $source,
        );
    }

    public function testTheWizardHasNoTabsToLeaveInTheWrongState(): void
    {
        // Este teste substitui um que verificava que abrir o "adicionar" reponha os dois
        // botoes de separador, porque o mesmo modal servia os dois trabalhos e podia
        // ficar com o separador das configuracoes activo de uma edicao anterior.
        //
        // Com o assistente num modal proprio e sem separadores, esse estado nao existe
        // para ser reposto. O invariante deixou de ser "repor" e passou a ser "nao ter".
        $root = dirname(__DIR__, 3);
        $wizard = file_get_contents($root . '/src/Dashboard/components/modals/device-wizard.php');
        $edit = file_get_contents($root . '/src/Dashboard/components/modals/device.php');

        self::assertIsString($wizard);
        self::assertIsString($edit);
        self::assertStringNotContainsString('nav-link', $wizard);
        self::assertStringNotContainsString('tab-pane', $wizard);
        self::assertStringContainsString("render_modal('deviceWizardModal'", $wizard);

        // O modal de edicao mantem os seus separadores, que e o que esta mudanca nao toca.
        self::assertStringContainsString('deviceConfigTabBtn', $edit);
        self::assertStringContainsString('deviceGeneralTabBtn', $edit);
    }

}
