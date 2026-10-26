<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceHubServer;
use Hub\Ingress\Tcp\Zayata\PillDispenserAdapter;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeConnection;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Um dispositivo apagado da whitelist com a ligação aberta. */
final class RemovedDeviceSessionTest extends TestCase
{
    private const DISPENSER = 'AABBCCDDEEFF';
    private const WATCH = '865028000000308';

    private int $now = 1_000;
    private Whitelist $whitelist;
    private RecordingHubMqttBridge $mqtt;
    private DeviceHubServer $hub;

    protected function setUp(): void
    {
        $this->whitelist = IngressFixtures::whitelist([
            self::DISPENSER => IngressFixtures::device('Zayata', 'M228', 'pill_dispenser'),
            self::WATCH => ['supplier' => 'Vivistar', 'model' => 'VIVISTAR-CARE'],
        ]);
        $this->mqtt = new RecordingHubMqttBridge();
        $this->hub = new DeviceHubServer($this->whitelist, $this->mqtt, clock: fn (): int => $this->now);
    }

    /** O tipo que a sessão conheceu vale quando a whitelist já não sabe o do aparelho. */
    public function testAnEventAboutItKeepsTheTypeOfItsSession(): void
    {
        $connection = $this->connect(1, (new PillDispenserAdapter())->encodeOutgoing(['packetType' => 0x01, 'serial' => 1, 'mac' => self::DISPENSER]));
        $this->whitelist->unregister(self::DISPENSER);

        $this->hub->sendDownlink(self::DISPENSER, 'ping');

        self::assertSame('device.downlink.sent', end($this->mqtt->events)['type']);
        self::assertSame('pill_dispenser', end($this->mqtt->events)['deviceType']);
        self::assertFalse($connection->closed);
    }

    /** Apagado é apagado: a ligação fecha e não se anuncia desligado no tópico que acabou de ser limpo. */
    public function testDisconnectingItClosesTheSessionWithoutAnnouncingIt(): void
    {
        $connection = $this->connect(2, 'IWAP00' . self::WATCH . '#');
        $statuses = count($this->mqtt->statuses);
        $this->whitelist->unregister(self::WATCH);

        $this->hub->disconnect(self::WATCH);
        $this->hub->onClose($connection);
        $this->now += 3600;
        $this->hub->announceClosedConnections();

        self::assertTrue($connection->closed);
        self::assertCount($statuses, $this->mqtt->statuses);
    }

    private function connect(int $id, string $login): FakeConnection
    {
        $connection = new FakeConnection($id);
        $this->hub->onOpen($connection);
        $this->hub->onMessage($connection, $login);
        return $connection;
    }
}
