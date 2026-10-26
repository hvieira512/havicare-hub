<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use Hub\Ingress\Tcp\DeviceHubServer;
use Hub\Ingress\Tcp\HubTcpIngress;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\LocalTcpPort;
use Tests\Support\Doubles\RecordingHubMqttBridge;

final class FourPTouchTcpHandshakeTest extends TestCase
{
    private Whitelist $whitelist;

    protected function setUp(): void
    {
        $this->whitelist = IngressFixtures::whitelist([
            '637507597567372' => ['supplier' => '4P Touch', 'model' => '4P-TOUCH', 'deviceId' => '7597567372'],
        ]);
    }

    public function testFourPTouchLinkKeepGetsAckOverTcp(): void
    {
        $loop = new StreamSelectLoop();
        $port = LocalTcpPort::free();
        if ($port === null) {
            self::markTestSkipped('Local TCP sockets are not available in this environment');
        }

        $mqtt = new RecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
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
                    $connection->write('[3G*7597567372*000D*LK,50,100,100]');
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
        self::assertSame('[3G*7597567372*0002*LK]', $received);
        self::assertCount(1, $mqtt->statuses);
        self::assertCount(1, $mqtt->events);
        self::assertCount(1, $mqtt->raw);
        self::assertSame('637507597567372', $mqtt->statuses[0]['imei']);
        self::assertSame('online', $mqtt->statuses[0]['payload']['state']);
        self::assertSame('4P Touch', $mqtt->statuses[0]['payload']['device']['supplier']);
        self::assertSame('4P-TOUCH', $mqtt->statuses[0]['payload']['device']['model']);
        self::assertSame('device.connected', $mqtt->events[0]['payload']['type']);
        $telemetry = array_column(array_column($mqtt->telemetry, 'payload'), null, 'type');
        self::assertEqualsCanonicalizing(['activity', 'battery'], array_keys($telemetry));
        self::assertArrayNotHasKey('schemaVersion', $telemetry['battery']);
        self::assertSame('four-p-touch', $telemetry['battery']['source']['protocol']);
        self::assertSame('LK', $telemetry['battery']['source']['nativeType']);
        self::assertSame('text', $mqtt->raw[0]['payload']['debug']['encoding']);
    }
}
