<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\DeviceAuthorizer;
use Hub\DeviceIdentity;
use Hub\Registry\Whitelist;
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
            '637507597567372' => ['supplier' => '4P Touch', 'model' => '4P-TOUCH', 'deviceId' => '7597567372'],
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
        $authorizer = new DeviceAuthorizer(
            new Whitelist($this->whitelistPath),
            $this->commercialResolver()
        );

        $result = $authorizer->authorize(new DeviceIdentity('865028000000306', 'wonlex-json'));

        self::assertTrue($result->allowed);
        self::assertSame('Wonlex', $result->supplier);
        self::assertSame('HW20PRO', $result->model);
        self::assertSame('Wonlex HW20 Pro', $result->commercialName);
    }

    public function testRejectsUnknownDevice(): void
    {
        $authorizer = new DeviceAuthorizer(
            new Whitelist($this->whitelistPath),
            $this->commercialResolver()
        );

        self::assertSame(
            'device_not_authorized',
            $authorizer->authorize(new DeviceIdentity('865028000000999', 'wonlex-json'))->reason
        );
    }

    public function testIgnoresDeviceClaimedModelAndReturnsWhitelistMetadata(): void
    {
        $authorizer = new DeviceAuthorizer(
            new Whitelist($this->whitelistPath),
            $this->commercialResolver()
        );

        $result = $authorizer->authorize(new DeviceIdentity('865028000000306', 'wonlex-json', 'DEVICE-CLAIMED-MODEL'));

        self::assertTrue($result->allowed);
        self::assertSame('Wonlex', $result->supplier);
        self::assertSame('HW20PRO', $result->model);
    }

    public function testResolvesFourPTouchProtocolIdToCanonicalImei(): void
    {
        $authorizer = new DeviceAuthorizer(
            new Whitelist($this->whitelistPath),
            $this->commercialResolver()
        );

        $result = $authorizer->authorize(new DeviceIdentity('7597567372', 'four-p-touch', ident: '7597567372'));

        self::assertTrue($result->allowed);
        self::assertSame('637507597567372', $result->imei);
        self::assertSame('4P Touch', $result->supplier);
        self::assertSame('4P-TOUCH', $result->model);
        self::assertSame('4P Touch D46', $result->commercialName);
    }

    private function commercialResolver(): \Hub\CommercialModelResolver
    {
        return new class extends \Hub\CommercialModelResolver {
            public function __construct()
            {
            }

            public function resolveCommercialName(string $supplier, string $model): string
            {
                if ($supplier === 'Wonlex' && $model === 'HW20PRO') {
                    return 'Wonlex HW20 Pro';
                }
                if ($supplier === 'Wonlex' && $model === 'WONLEX-HEALTH') {
                    return 'Wonlex Health';
                }
                if ($supplier === '4P Touch' && $model === '4P-TOUCH') {
                    return '4P Touch D46';
                }

                return '';
            }
        };
    }
}
