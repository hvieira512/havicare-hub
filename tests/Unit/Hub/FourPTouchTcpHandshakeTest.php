<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use App\Hub\DeviceHubServer;
use App\Hub\HubMqttBridge;
use App\Hub\HubTcpIngress;
use App\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

final class FourPTouchTcpHandshakeTest extends TestCase
{
    private string $whitelistPath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-4p-touch-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, json_encode([
            '8800000015' => ['supplier' => '4P Touch', 'model' => '4P-TOUCH'],
        ]));
    }

    protected function tearDown(): void
    {
        if (is_file($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
    }

    public function testFourPTouchLinkKeepGetsAckOverTcp(): void
    {
        $loop = new StreamSelectLoop();
        $port = $this->freeTcpPort();
        if ($port === null) {
            self::markTestSkipped('Local TCP sockets are not available in this environment');
        }

        $mqtt = new RecordingHubMqttBridge();
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);
        new HubTcpIngress($hub, $loop, '127.0.0.1', $port);

        $received = '';
        $error = null;

        $connector = new Connector($loop);
        $loop->addTimer(0.01, static function () use ($connector, $port, &$received, &$error, $loop): void {
            $connector->connect("tcp://127.0.0.1:$port")->then(
                static function (ConnectionInterface $connection) use (&$received, $loop): void {
                    $connection->on('data', static function (string $data) use (&$received, $connection, $loop): void {
                        $received .= $data;
                        if (str_contains($received, ']')) {
                            $connection->end();
                            $loop->stop();
                        }
                    });
                    $connection->write('[3G*8800000015*000D*LK,50,100,100]');
                },
                static function (\Throwable $e) use (&$error, $loop): void {
                    $error = $e;
                    $loop->stop();
                }
            );
        });
        $loop->addTimer(1.0, static function () use (&$error, $loop): void {
            $error = $error ?? new \RuntimeException('Timed out waiting for 4P Touch ACK');
            $loop->stop();
        });

        $loop->run();

        self::assertNull($error, $error?->getMessage() ?? '');
        self::assertSame('[3G*8800000015*0002*LK]', $received);
        self::assertCount(1, $mqtt->statuses);
        self::assertCount(4, $mqtt->events);
        self::assertCount(1, $mqtt->raw);
        self::assertSame('online', $mqtt->statuses[0][1]['state']);
        self::assertSame('4P Touch', $mqtt->statuses[0][1]['device']['supplier']);
        self::assertSame('4P-TOUCH', $mqtt->statuses[0][1]['device']['model']);
        self::assertSame('device.connected', $mqtt->events[0][1]['type']);
        self::assertSame('device.telemetry.heartbeat', $mqtt->events[1][1]['type']);
        self::assertSame(2, $mqtt->events[1][1]['schemaVersion']);
        self::assertSame('four-p-touch', $mqtt->events[1][1]['source']['protocol']);
        self::assertSame('LK', $mqtt->events[1][1]['source']['nativeType']);
        self::assertSame('device.telemetry.activity', $mqtt->events[2][1]['type']);
        self::assertSame('device.telemetry.battery', $mqtt->events[3][1]['type']);
        self::assertSame('text', $mqtt->raw[0][1]['debug']['encoding']);
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

final class RecordingHubMqttBridge extends HubMqttBridge
{
    public array $raw = [];
    public array $statuses = [];
    public array $events = [];

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
}
