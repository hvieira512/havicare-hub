<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use App\Hub\DeviceAuthorizer;
use App\Hub\DeviceIdentity;
use App\Registry\Whitelist;
use PHPUnit\Framework\TestCase;

final class DeviceAuthorizerTest extends TestCase
{
    private string $whitelistPath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, json_encode([
            '865028000000306' => ['supplier' => 'Wonlex', 'model' => 'HW20PRO'],
            '865028000000307' => ['supplier' => 'Wonlex', 'model' => 'WONLEX-HEALTH'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
    }

    public function testAllowsAuthorizedDeviceAndReturnsExpectedModel(): void
    {
        $authorizer = new DeviceAuthorizer(new Whitelist($this->whitelistPath));

        $result = $authorizer->authorize(new DeviceIdentity('865028000000306', 'wonlex-json'));

        self::assertTrue($result->allowed);
        self::assertSame('Wonlex', $result->supplier);
        self::assertSame('HW20PRO', $result->model);
    }

    public function testRejectsUnknownDevice(): void
    {
        $authorizer = new DeviceAuthorizer(new Whitelist($this->whitelistPath));

        self::assertSame(
            'device_not_authorized',
            $authorizer->authorize(new DeviceIdentity('865028000000999', 'wonlex-json'))->reason
        );
    }

    public function testIgnoresDeviceClaimedModelAndReturnsWhitelistMetadata(): void
    {
        $authorizer = new DeviceAuthorizer(new Whitelist($this->whitelistPath));

        $result = $authorizer->authorize(new DeviceIdentity('865028000000306', 'wonlex-json', 'DEVICE-CLAIMED-MODEL'));

        self::assertTrue($result->allowed);
        self::assertSame('Wonlex', $result->supplier);
        self::assertSame('HW20PRO', $result->model);
    }
}
