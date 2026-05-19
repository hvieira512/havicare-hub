<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Registry\Whitelist;
use App\Services\CommandService;
use App\Services\ServiceException;
use PHPUnit\Framework\TestCase;

final class CommandServiceTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'whitelist-') ?: sys_get_temp_dir() . '/whitelist-cmd-test.json';
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

    public function testSendCommandValidatesCommandType(): void
    {
        $service = new CommandService(new Whitelist($this->tmpFile, null), null, null);

        try {
            $service->sendCommand('865028000000306', []);
            self::fail('Expected exception not thrown');
        } catch (ServiceException $e) {
            self::assertSame('invalid_request', $e->codeName());
            self::assertSame(400, $e->status());
        }
    }

    public function testSendCommandReturnsDeviceOfflineWhenNoDispatcherAvailable(): void
    {
        $service = new CommandService(new Whitelist($this->tmpFile, null), null, null);

        try {
            $service->sendCommand('865028000000306', ['type' => 'dnHeartRate', 'data' => []]);
            self::fail('Expected exception not thrown');
        } catch (ServiceException $e) {
            self::assertSame('device_offline', $e->codeName());
            self::assertSame(503, $e->status());
        }
    }
}
