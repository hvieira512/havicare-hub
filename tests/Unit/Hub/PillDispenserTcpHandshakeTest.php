<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceHubServer;
use Hub\Device\HubTcpIngress;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\LocalTcpPort;
use Tests\Support\Doubles\RecordingHubMqttBridge;

final class PillDispenserTcpHandshakeTest extends TestCase
{
    private Whitelist $whitelist;

    protected function setUp(): void
    {
        $this->whitelist = IngressFixtures::whitelist([
            'AABBCCDDEEFF' => IngressFixtures::device('Zayata', 'M228', 'pill_dispenser'),
        ]);
    }

    public function testRegisterBringsDeviceOnlineAndMedicationEventReachesEvents(): void
    {
        $loop = new StreamSelectLoop();
        $port = LocalTcpPort::free();
        if ($port === null) {
            self::markTestSkipped('Local TCP sockets are not available in this environment');
        }

        $mqtt = new RecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        new HubTcpIngress($hub, $loop, '127.0.0.1', $port);

        $adapter = new PillDispenserAdapter();
        // Registo e evento de medicação no mesmo envio: o enquadramento tem de os separar.
        $register = $adapter->encodeOutgoing(['packetType' => 0x01, 'serial' => 1, 'mac' => 'AABBCCDDEEFF']);
        $event = $adapter->encodeOutgoing([
            'packetType' => 0x03,
            'serial' => 2,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0xC201 => ['value' => "\x02"],
                0xC203 => ['value' => '2026-09-18T20:05:04'],
                0xC204 => ['value' => "\x0C"],
                0xC206 => ['value' => "\x03"],
            ],
        ]);

        $received = '';
        $error = null;
        $connector = new Connector($loop);
        $loop->addTimer(0.01, static function () use ($connector, $port, $register, $event, &$received, &$error, $loop): void {
            $connector->connect("tcp://127.0.0.1:$port")->then(
                static function (ConnectionInterface $connection) use ($register, $event, &$received, $loop): void {
                    $connection->on('data', static function (string $data) use (&$received, $connection, $loop): void {
                        $received .= $data;
                        // Espera pelos dois ACKs (registo 0x81 e evento 0x83).
                        if (substr_count($received, "\xAA") >= 2) {
                            $connection->end();
                            $loop->stop();
                        }
                    });
                    $connection->write($register . $event);
                },
                static function (\Throwable $e) use (&$error, $loop): void {
                    $error = $e;
                    $loop->stop();
                }
            );
        });
        $loop->addTimer(1.0, static function () use (&$error, $loop): void {
            $error = $error ?? new \RuntimeException('Timed out waiting for pill dispenser ACKs');
            $loop->stop();
        });

        $loop->run();

        self::assertNull($error, $error?->getMessage() ?? '');

        // Ligou e foi reconhecido pelo protocolo certo.
        self::assertCount(1, $mqtt->statuses);
        self::assertSame('online', $mqtt->statuses[0]['payload']['state']);
        self::assertSame('device.connected', $mqtt->events[0]['payload']['type']);

        // O ACK do registo descodifica como register_ack com a mesma identidade. O primeiro
        // frame mede-se pelo seu campo de comprimento — o 0xAA também aparece dentro da trama.
        $firstLength = 3 + unpack('v', substr($received, 1, 2))[1] + 2;
        $ack = $adapter->decodeIncoming(substr($received, 0, $firstLength));
        self::assertIsArray($ack);
        self::assertSame('register_ack', $ack['type']);
        self::assertSame('AABBCCDDEEFF', $ack['imei']);

        // A toma falhada saiu pelo canal de eventos, não pela telemetria.
        $intake = array_values(array_filter(
            $mqtt->events,
            static fn (array $entry): bool => $entry['type'] === 'medication_intake'
        ));
        self::assertCount(1, $intake);
        self::assertSame('missed', $intake[0]['payload']['data']['result']);
        self::assertSame(3, $intake[0]['payload']['data']['alarmSlot']);
    }
}
