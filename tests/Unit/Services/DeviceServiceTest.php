<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Registry\Whitelist;
use App\Services\DeviceService;
use App\Services\ServiceException;
use PHPUnit\Framework\TestCase;

final class DeviceServiceTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'whitelist-') ?: sys_get_temp_dir() . '/whitelist-test.json';
        file_put_contents($this->tmpFile, json_encode([
            '865028000000306' => [
                'model' => 'WONLEX-PRO',
                'enabled' => true,
                'registered_at' => '2026-01-01T00:00:00Z',
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    public function testListDevicesUsesWhitelistFallbackWithoutDatabase(): void
    {
        $whitelist = new Whitelist($this->tmpFile, null);
        $service = new DeviceService($whitelist, null, null, null);

        $result = $service->listDevices(['page' => 1, 'limit' => 25]);

        self::assertCount(1, $result['data']);
        self::assertSame('865028000000306', $result['data'][0]['imei']);
        self::assertSame(1, $result['pagination']['total']);
    }

    public function testCreateDeviceRequiresMysql(): void
    {
        $whitelist = new Whitelist($this->tmpFile, null);
        $service = new DeviceService($whitelist, null, null, null);

        try {
            $service->createDevice(['imei' => '865028000000307', 'model' => 'WONLEX-PRO']);
            self::fail('Expected exception not thrown');
        } catch (ServiceException $e) {
            self::assertSame('mysql_unavailable', $e->codeName());
            self::assertSame(503, $e->status());
        }
    }
}
