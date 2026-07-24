<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Services;

use PHPUnit\Framework\TestCase;

final class DeviceServiceSourceTest extends TestCase
{
    public function testConfigurationResponsibilitiesAreDelegatedToFocusedServices(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Api/Services/DeviceService.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('DeviceConfigurationQueryService', $source);
        self::assertStringContainsString('DeviceConfigurationUpdateService', $source);
        self::assertStringNotContainsString('persistAndApplyConfiguration', $source);
        self::assertStringNotContainsString("'_nativeKey'", $source);
    }

    public function testDeviceServiceSkipsUnsupportedContractDefaultsAndEntries(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Api/Services/DeviceService.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'supportsProtocol($genericKey, $protocol)',
            $source,
        );
        self::assertStringContainsString(
            'if ($entry === []) {',
            $source,
        );
        self::assertStringContainsString(
            'if ($hasContract && !$this->capabilityRegistry->supportsProtocol($genericKey, $protocol))',
            $source,
        );
        self::assertStringContainsString(
            'continue;',
            $source,
        );
    }
}
