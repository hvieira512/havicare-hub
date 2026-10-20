<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Veepoo\BraceletPresence;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O estado que conta é o do Redis, que a manutenção também muda, e não o que este processo se lembra. */
final class BraceletPresenceTest extends TestCase
{
    private const DEVICE = ['supplier' => 'Unique Scales', 'model' => 'HC-CF632BLE'];

    public function testAfterTheHubGaveItUpTheNextSessionIsAConnection(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $store = new DeviceStore(new InMemoryRedisClient());
        $presence = new BraceletPresence($mqtt, $store, 'lefu-torre', 'scale');

        $presence->markOnline('cfe908067329', self::DEVICE, 0, 'null');
        $store->deviceOffline('cfe908067329');
        $presence->markOnline('cfe908067329', self::DEVICE, 0, 'null');

        self::assertSame(['device.connected', 'device.connected'], array_column($mqtt->events, 'type'));
    }

    /** Um hub acabado de arrancar não sabe que o aparelho estava ligado, e a queda conta na mesma. */
    public function testADisconnectAfterARestartIsAnnounced(): void
    {
        $mqtt = new RecordingHubMqttBridge();

        (new BraceletPresence($mqtt, new DeviceStore(new InMemoryRedisClient()), 'lefu-torre', 'scale'))->markOffline('cfe908067329', self::DEVICE, 0, 'null');

        self::assertSame(['offline'], array_column(array_column($mqtt->statuses, 'payload'), 'state'));
    }
}
