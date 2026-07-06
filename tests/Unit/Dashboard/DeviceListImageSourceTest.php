<?php

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class DeviceListImageSourceTest extends TestCase
{
    public function testDeviceListRendersFromDeviceImageWithoutModelCatalogLookup(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Dashboard/dashboard/devices/list-detail.js'
        );

        self::assertIsString($source);
        self::assertStringContainsString('device.image', $source);
        self::assertStringContainsString('device.model || "-"', $source);
        self::assertStringNotContainsString('ensureModelsLoaded(),', $source);
        self::assertStringNotContainsString('modelLookup', $source);
        self::assertStringContainsString('renderSelectedDeviceSummary(device, deviceModel)', $source);
        self::assertStringContainsString('deviceModel?.image', $source);
        self::assertStringContainsString('deviceModel?.commercialName', $source);
        self::assertStringNotContainsString('findModelInfo(supplier, model)', $source);
        self::assertStringNotContainsString('modelImageHtml(modelInfo)', $source);
    }
}
