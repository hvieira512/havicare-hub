<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Command\DeviceCommandCatalog;
use Hub\Dashboard\DashboardStore;
use Hub\Device\DeviceHubServer;
use Hub\Device\HubTcpIngress;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\LocalTcpPort;

/**
 * Na primeira vez que vê um aparelho, o hub pergunta-lhe que parâmetros é que ele serve.
 *
 * As listas estavam escritas à mão no adaptador, tiradas de uma descoberta corrida uma vez
 * contra um aparelho. Um firmware diferente recusa TAGs que nós pedimos na mesma, e o cartão
 * fica vazio sem ninguém saber porquê.
 */
final class PillDispenserFirstRegistrationDiscoveryTest extends TestCase
{
    public function testTheDispenserIsAskedForItsThreeParameterLists(): void
    {
        self::assertSame([
            'discoverParametersConfiguration',
            'discoverParametersStatus',
            'discoverParametersControl',
        ], DeviceCommandCatalog::firstRegistrationCommands('zayata-m228'));
    }

    /** Só este protocolo tem descoberta; os outros não ganham tráfego novo por causa disto. */
    public function testNoOtherProtocolIsAskedAnything(): void
    {
        foreach (['wonlex-json', '4p-touch', 'veepoo-mqtt', ''] as $protocol) {
            self::assertSame([], DeviceCommandCatalog::firstRegistrationCommands($protocol), $protocol);
        }
    }

    /** E o registo tem de as pôr no fio, que é a parte que o catálogo sozinho não prova. */
    public function testRegisteringPutsTheThreeDiscoveryPacketsOnTheWire(): void
    {
        $loop = new StreamSelectLoop();
        $port = LocalTcpPort::free();
        if ($port === null) {
            self::markTestSkipped('Local TCP sockets are not available in this environment');
        }

        $whitelist = IngressFixtures::whitelist([
            'AABBCCDDEEFF' => IngressFixtures::device('Zayata', 'M228', 'pill_dispenser'),
        ]);
        $hub = new DeviceHubServer($whitelist, new WonlexRecordingHubMqttBridge());
        new HubTcpIngress($hub, $loop, '127.0.0.1', $port);

        $adapter = new PillDispenserAdapter();
        $register = $adapter->encodeOutgoing(['packetType' => 0x01, 'serial' => 1, 'mac' => 'AABBCCDDEEFF']);

        $received = '';
        $connector = new Connector($loop);
        $loop->addTimer(0.01, static function () use ($connector, $port, $register, &$received, $loop): void {
            $connector->connect("tcp://127.0.0.1:$port")->then(
                static function (ConnectionInterface $connection) use ($register, &$received, $loop): void {
                    $connection->on('data', static function (string $data) use (&$received, $connection, $loop): void {
                        $received .= $data;
                        // O ACK do registo mais as três perguntas.
                        if (substr_count($received, "\xAA") >= 4) {
                            $connection->end();
                            $loop->stop();
                        }
                    });
                    $connection->write($register);
                },
                static function () use ($loop): void {
                    $loop->stop();
                }
            );
        });
        $loop->addTimer(1.0, static fn (): bool => (bool)$loop->stop());
        $loop->run();

        self::assertSame([0x81, 0x0A, 0x0B, 0x0C], self::packetTypes($received));
    }

    /**
     * É a **primeira** vez, e não todas: um aparelho que se religa dez vezes por dia não
     * responde dez vezes à mesma pergunta.
     */
    public function testTheSecondRegistrationAsksNothing(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient());

        self::assertTrue($store->claimParameterDiscovery('AABBCCDDEEFF'));
        self::assertFalse($store->claimParameterDiscovery('AABBCCDDEEFF'));
    }

    /** E a marca é por aparelho: o segundo dispensador tem a sua primeira vez. */
    public function testEachDeviceHasItsOwnFirstTime(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient());
        $store->claimParameterDiscovery('AABBCCDDEEFF');

        self::assertTrue($store->claimParameterDiscovery('112233445566'));
    }

    /**
     * Os tipos de pacote das tramas recebidas, pela ordem em que chegaram.
     *
     * @return list<int>
     */
    private static function packetTypes(string $stream): array
    {
        $types = [];
        $offset = 0;
        while ($offset + 20 <= strlen($stream)) {
            $length = unpack('v', substr($stream, $offset + 1, 2))[1];
            $types[] = ord($stream[$offset + 19]);
            $offset += $length + 5;
        }

        return $types;
    }
}
