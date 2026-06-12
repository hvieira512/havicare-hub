<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use App\Hub\DeviceHubServer;
use App\Hub\HubMqttBridge;
use App\Hub\HubTcpIngress;
use App\Protocol\Adapter\WonlexAdapter;
use App\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

final class WonlexTcpHandshakeTest extends TestCase
{
    private string $whitelistPath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-wonlex-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, json_encode([
            '868705080300697' => ['supplier' => 'Wonlex', 'model' => 'HW20PRO'],
        ]));
    }

    protected function tearDown(): void
    {
        if (is_file($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
    }

    public function testWonlexLoginGetsReplyOverTcp(): void
    {
        $loop = new StreamSelectLoop();
        $port = $this->freeTcpPort();
        if ($port === null) {
            self::markTestSkipped('Local TCP sockets are not available in this environment');
        }

        $mqtt = new WonlexRecordingHubMqttBridge();
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);
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

    private function freeTcpPort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            return null;
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $parts = explode(':', (string)$name);
        return (int)array_pop($parts);
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

    public function publishRaw(string $imei, array $payload): void
    {
        $this->raw[] = [$imei, $payload];
    }

    public function publishStatus(string $imei, array $payload, bool $retain = true): void
    {
        $this->statuses[] = [$imei, $payload, $retain];
    }

    public function publishEvent(string $imei, array $payload): void
    {
        $this->events[] = [$imei, $payload];
    }

    public function publishTelemetry(string $imei, array $payload): void
    {
        $this->telemetry[] = [$imei, $payload];
    }
}
