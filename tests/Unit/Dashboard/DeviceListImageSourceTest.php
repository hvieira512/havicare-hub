<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class DeviceListImageSourceTest extends TestCase
{
    public function testDeviceListRendersFromDeviceImageWithoutModelCatalogLookup(): void
    {
        $listSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/list-detail.js'
        );
        $detailSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/detail-view.js'
        );

        self::assertIsString($listSource);
        self::assertIsString($detailSource);

        self::assertStringContainsString('device.image', $listSource);
        // O modelo e o fornecedor vêm da própria linha e não de uma consulta ao catálogo:
        // o cartão junta-os na linha de contexto sob o IMEI.
        self::assertStringContainsString('[device.supplier, device.model]', $listSource);
        self::assertStringNotContainsString('ensureModelsLoaded(),', $listSource);
        self::assertStringNotContainsString('modelLookup', $listSource);
        self::assertStringNotContainsString('findModelInfo(supplier, model)', $listSource);
        self::assertStringNotContainsString('modelImageHtml(modelInfo)', $listSource);

        preg_match(
            '/function renderSelectedDeviceSummary\\(device, deviceModel, linkedDevices = \\[\\]\\) \\{.*?^\\}/sm',
            $detailSource,
            $summaryMatch,
        );
        self::assertNotEmpty($summaryMatch, 'renderSelectedDeviceSummary body not found');
        $summarySource = $summaryMatch[0];

        self::assertStringContainsString('renderSelectedDeviceSummary(', $detailSource);
        self::assertStringContainsString('deviceModel?.image', $detailSource);
        // A empresa e a licenca sao um campo so, `empresa · numero` ou "Sem licenca": a
        // licenca pertence a empresa e um dispositivo tem as duas ou nenhuma, logo dois
        // campos separados podiam mostrar uma empresa sem licenca, que nao existe.
        self::assertStringContainsString('deviceLicenseHtml(device)', $summarySource);
        self::assertStringNotContainsString('label: "Empresa"', $summarySource);
        self::assertStringContainsString('deviceTypeLabel(', $summarySource);
        self::assertStringContainsString('selectedDeviceMeta.textContent = `${typeLabel} ·', $summarySource);
        self::assertStringNotContainsString('label: "Fornecedor"', $summarySource);
        self::assertStringNotContainsString('label: "Modelo"', $summarySource);
        self::assertStringNotContainsString('label: "Device ID"', $summarySource);
    }
}
