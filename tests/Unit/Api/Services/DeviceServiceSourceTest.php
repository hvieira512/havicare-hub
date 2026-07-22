<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Services;

use PHPUnit\Framework\TestCase;

final class DeviceServiceSourceTest extends TestCase
{
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
