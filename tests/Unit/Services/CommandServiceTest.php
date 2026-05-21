<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Registry\Whitelist;
use App\Services\CommandService;
use App\Services\ServiceException;
use App\WebSocket\WatchServer;
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

    public function testDeviceFeaturesIncludesCommandMetadataAndHints(): void
    {
        $service = new CommandService(new Whitelist($this->tmpFile, null), null, null);

        $payload = $service->deviceFeatures('865028000000306');

        self::assertIsArray($payload['data']['commandMetadata'] ?? null);
        self::assertNotEmpty($payload['data']['commandMetadata']);
        self::assertIsArray($payload['data']['commandStateHints'] ?? null);
        self::assertSame(
            'Device replied and command was acknowledged.',
            $payload['data']['commandStateHints']['ack'] ?? null
        );
    }

    public function testMeasureFeatureReturnsPollPathWhenDispatchSucceeds(): void
    {
        $watchServer = $this->getMockBuilder(WatchServer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendCommand'])
            ->getMock();

        $watchServer->expects(self::once())
            ->method('sendCommand')
            ->with(
                '865028000000306',
                'dnHeartRate',
                ['priority' => 'high'],
                self::isType('string'),
                'heart_rate'
            )
            ->willReturn(true);

        $service = new CommandService(new Whitelist($this->tmpFile, null), $watchServer, null);
        $payload = $service->measureFeature('865028000000306', 'heart_rate', [
            'data' => ['priority' => 'high'],
        ]);

        self::assertSame('requested', $payload['status']);
        self::assertSame('heart_rate', $payload['measurement']['feature'] ?? null);
        self::assertSame('dnHeartRate', $payload['measurement']['nativeType'] ?? null);
        self::assertNotEmpty($payload['measurement']['requestId'] ?? null);
        self::assertIsInt($payload['measurement']['requestedAt'] ?? null);
        self::assertSame(
            '/devices/865028000000306/features/heart_rate/latest',
            $payload['poll']['latestFeaturePath'] ?? null
        );
    }
}
