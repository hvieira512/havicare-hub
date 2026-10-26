<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use Hub\Ingress\Tcp\DeviceHubServer;
use Hub\Ingress\Tcp\Zayata\ZayataAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeConnection;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Um `'watch'` por omissão publicaria um dispensador debaixo de `/watch/` sem erro; o tipo sai da whitelist. */
final class TcpLayerHasNoDeviceTypeDefaultTest extends TestCase
{
    public function testADispenserPublishesUnderItsOwnDeviceType(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $hub = new DeviceHubServer(IngressFixtures::whitelist([
            'AABBCCDDEEFF' => IngressFixtures::device('Zayata', 'M228', 'pill_dispenser'),
        ]), $mqtt);
        $adapter = new ZayataAdapter();
        $connection = new FakeConnection();

        $hub->onOpen($connection);
        $hub->onMessage($connection, $adapter->encodeOutgoing(['packetType' => 0x01, 'serial' => 1, 'mac' => 'AABBCCDDEEFF']));
        $hub->onMessage($connection, $adapter->encodeOutgoing([
            'packetType' => 0x02,
            'serial' => 2,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [0x8103 => ['value' => "\x50"]],
        ]));

        $published = [...$mqtt->statuses, ...$mqtt->events, ...$mqtt->telemetry, ...$mqtt->raw];
        self::assertNotSame([], $mqtt->telemetry, 'a leitura tem de chegar à telemetria');
        self::assertSame(['pill_dispenser'], array_values(array_unique(array_column($published, 'deviceType'))));
    }

    /** Sem whitelist, o tipo sai do protocolo por que o aparelho fala. */
    public function testARejectedDispenserIsPublishedUnderItsOwnDeviceType(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $hub = new DeviceHubServer(IngressFixtures::whitelist(), $mqtt);
        $connection = new FakeConnection();

        $hub->onOpen($connection);
        $hub->onMessage($connection, (new ZayataAdapter())->encodeOutgoing(['packetType' => 0x01, 'serial' => 1, 'mac' => 'AABBCCDDEEFF']));

        $published = [...$mqtt->statuses, ...$mqtt->events];
        self::assertNotSame([], $published, 'a recusa tem de sair no broker');
        self::assertSame(['pill_dispenser'], array_values(array_unique(array_column($published, 'deviceType'))));
    }
}
