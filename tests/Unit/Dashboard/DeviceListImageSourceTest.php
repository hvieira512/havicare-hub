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
        self::assertStringContainsString('device.model || "-"', $listSource);
        self::assertStringNotContainsString('ensureModelsLoaded(),', $listSource);
        self::assertStringNotContainsString('modelLookup', $listSource);
        self::assertStringNotContainsString('findModelInfo(supplier, model)', $listSource);
        self::assertStringNotContainsString('modelImageHtml(modelInfo)', $listSource);

        self::assertStringContainsString('renderSelectedDeviceSummary(device, deviceModel)', $detailSource);
        self::assertStringContainsString('deviceModel?.image', $detailSource);
        self::assertStringContainsString('deviceModel?.commercialName', $detailSource);
    }
}
