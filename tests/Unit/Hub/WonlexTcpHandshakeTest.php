<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\DeviceHubServer;
use Hub\HubMqttBridge;
use Hub\HubTcpIngress;
use Hub\Protocol\Adapter\WonlexAdapter;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\LocalTcpPort;

final class WonlexTcpHandshakeTest extends TestCase
{
    private Whitelist $whitelist;

    protected function setUp(): void
    {
        $this->whitelist = IngressFixtures::whitelist([
            '868705080300697' => [
                'supplier' => 'Wonlex',
                'model' => 'HW20PRO',
                'licenseId' => '1001',
                'company' => 'hitcare',
            ],
        ]);
    }

    public function testWonlexLoginGetsReplyOverTcp(): void
    {
        $loop = new StreamSelectLoop();
        $port = LocalTcpPort::free();
        if ($port === null) {
            self::markTestSkipped('Local TCP sockets are not available in this environment');
        }

        $mqtt = new WonlexRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        new HubTcpIngress($hub, $loop, '127.0.0.1', $port);

        $received = '';
        $error = null;
        $adapter = new WonlexAdapter();
        $frame = $adapter->encodeOutgoing([
            'type' => 'login',
            'ident' => 614377,
            'ref' => 'w:update',
            'imei' => '868705080300697',
            'data' => [
                'type' => 'login',
                'imei' => '868705080300697',
                'deviceModel' => 'HW20PRO',
            ],
            'timestamp' => 1780995537330,
        ]);

        $connector = new Connector($loop);
        $loop->addTimer(0.01, static function () use ($connector, $port, $frame, &$received, &$error, $loop): void {
            $connector->connect("tcp://127.0.0.1:$port")->then(
                static function (ConnectionInterface $connection) use ($frame, &$received, $loop): void {
                    $connection->on('data', static function (string $data) use (&$received, $connection, $loop): void {
                        $received .= $data;
                        if (strlen($received) >= 4) {
                            $header = unpack('nstart/nlength', substr($received, 0, 4));
                            $expectedLength = 4 + (int)($header['length'] ?? 0);
                            if (($header['start'] ?? null) === 0xFCAF && strlen($received) >= $expectedLength) {
                                $connection->end();
                                $loop->stop();
                            }
                        }
                    });
                    $connection->write($frame);
                },
                static function (\Throwable $e) use (&$error, $loop): void {
                    $error = $e;
                    $loop->stop();
                }
            );
        });
        $loop->addTimer(1.0, static function () use (&$error, $loop): void {
            $error = $error ?? new \RuntimeException('Timed out waiting for Wonlex login ACK');
            $loop->stop();
        });

        $loop->run();

        self::assertNull($error, $error?->getMessage() ?? '');
        $reply = $adapter->decodeIncoming($received);
        self::assertIsArray($reply);
        self::assertSame('login', $reply['type']);
        self::assertSame('868705080300697', $reply['imei']);
        self::assertSame(1, $reply['data']['bindStatus']);
        self::assertCount(1, $mqtt->statuses);
        self::assertCount(1, $mqtt->events);
        self::assertCount(1, $mqtt->raw);
        self::assertSame('online', $mqtt->statuses[0][1]['state']);
        self::assertSame('Wonlex', $mqtt->statuses[0][1]['device']['supplier']);
        self::assertSame('HW20PRO', $mqtt->statuses[0][1]['device']['model']);
        self::assertSame('device.connected', $mqtt->events[0][1]['type']);
        self::assertSame('base64', $mqtt->raw[0][1]['debug']['encoding']);
    }
}

final class WonlexRecordingHubMqttBridge extends HubMqttBridge
{
    public array $raw = [];
    public array $statuses = [];
    public array $events = [];
    public array $telemetry = [];

    public function __construct()
    {
    }

    public function publishRaw(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
    {
        $this->raw[] = [$imei, $payload];
    }

    public function publishStatus(string $imei, array $payload, bool $retain = true, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
    {
        $this->statuses[] = [$imei, $payload, $retain];
    }

    public function publishEvent(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
    {
        $this->events[] = [$imei, $payload];
    }

    public function publishTelemetry(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
    {
        $this->telemetry[] = [$imei, $payload];
    }
}
