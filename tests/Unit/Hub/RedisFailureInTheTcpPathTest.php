<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceHubServer;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeConnection;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Uma exceção que escape do tratamento de uma trama TCP termina o processo do hub. */
final class RedisFailureInTheTcpPathTest extends TestCase
{
    private const IMEI = '865028000000308';

    public function testAFrameStillPublishesWhenRedisFails(): void
    {
        $redis = new InMemoryRedisClient();
        $mqtt = new RecordingHubMqttBridge();
        $hub = new DeviceHubServer(
            IngressFixtures::whitelist([self::IMEI => ['supplier' => 'Vivistar', 'model' => 'VIVISTAR-CARE']]),
            $mqtt,
            deviceStore: new DeviceStore($redis),
        );
        $connection = new FakeConnection(1);
        $hub->onOpen($connection);
        $redis->failOn = 'hget';

        $hub->onMessage($connection, 'IWAP00' . self::IMEI . '#');
        $hub->onMessage($connection, 'IWAP03,06000908000102,5555,30#');

        self::assertFalse($connection->closed);
        self::assertNotSame([], $mqtt->raw);
    }

    public function testAnOfflineAnnouncementSurvivesRedisFailing(): void
    {
        $now = 1_000;
        $redis = new InMemoryRedisClient();
        $mqtt = new RecordingHubMqttBridge();
        $hub = new DeviceHubServer(
            IngressFixtures::whitelist([self::IMEI => ['supplier' => 'Vivistar', 'model' => 'VIVISTAR-CARE']]),
            $mqtt,
            deviceStore: new DeviceStore($redis),
            clock: static function () use (&$now): int {
                return $now;
            },
        );
        $connection = new FakeConnection(1);
        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00' . self::IMEI . '#');
        $hub->onClose($connection);
        $redis->failOn = 'hmset';

        $now += 3600;
        $hub->announceClosedConnections();

        self::assertSame('offline', end($mqtt->statuses)['payload']['state']);
        self::assertSame('device.disconnected', end($mqtt->events)['payload']['type']);
    }
}
