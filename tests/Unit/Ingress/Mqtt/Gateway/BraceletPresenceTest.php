<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Gateway;

use Hub\Ingress\Mqtt\Gateway\BraceletPresence;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O estado que conta é o do Redis, que a manutenção também muda, e não o que este processo se lembra. */
final class BraceletPresenceTest extends TestCase
{
    private const DEVICE = ['supplier' => 'Unique Scales', 'model' => 'HC-CF632BLE', 'deviceType' => 'scale'];

    public function testAfterTheHubGaveItUpTheNextSessionIsAConnection(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $store = new DeviceStore(new InMemoryRedisClient());
        $presence = new BraceletPresence($mqtt, $store, 'lefu-torre');

        $presence->markOnline('cfe908067329', self::DEVICE);
        $store->deviceOffline('cfe908067329');
        $presence->markOnline('cfe908067329', self::DEVICE);

        self::assertSame(['device.connected', 'device.connected'], array_column($mqtt->events, 'type'));
    }

    /** A balança só se liga para medir: fora disso não está desligada, está à espera da próxima leitura. */
    public function testAnOnDemandDeviceIsMeasuringAndThenIdle(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $presence = new BraceletPresence($mqtt, new DeviceStore(new InMemoryRedisClient()), 'lefu-torre');

        $presence->markOnline('cfe908067329', self::DEVICE);
        $presence->markOffline('cfe908067329', self::DEVICE);

        self::assertSame(['measuring', 'idle'], array_column(array_column($mqtt->statuses, 'payload'), 'state'));
    }

    /** A pulseira mantém a sessão aberta e repete-a: essa liga-se e desliga-se. */
    public function testAContinuousDeviceIsOnlineAndThenOffline(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $presence = new BraceletPresence($mqtt, new DeviceStore(new InMemoryRedisClient()), 'veepoo-ble');

        $presence->markOnline('cfe908067329', self::DEVICE);
        $presence->markOffline('cfe908067329', self::DEVICE);

        self::assertSame(['online', 'offline'], array_column(array_column($mqtt->statuses, 'payload'), 'state'));
    }

    /** O gateway que diz que a sessão caiu está de pé: a queda é do aparelho. */
    public function testADroppedSessionSaysWhichGatewayReportedIt(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $store = new DeviceStore(new InMemoryRedisClient());
        $presence = new BraceletPresence($mqtt, $store, 'veepoo-ble');

        $presence->markOnline('cfe908067329', self::DEVICE, 'a1b2c3d4e5f6');
        $presence->markOffline('cfe908067329', self::DEVICE, 'a1b2c3d4e5f6');

        self::assertSame('a1b2c3d4e5f6', $store->runtime->device('cfe908067329')['gatewayId'] ?? null);
        self::assertSame([], array_column(array_column($mqtt->events, 'payload'), 'severity'));
        self::assertSame(['gatewayId' => 'a1b2c3d4e5f6'], $mqtt->events[1]['payload']['data']);
    }

    /** Um hub acabado de arrancar não sabe que o aparelho estava ligado, e a queda conta na mesma. */
    public function testADisconnectAfterARestartIsAnnounced(): void
    {
        $mqtt = new RecordingHubMqttBridge();

        (new BraceletPresence($mqtt, new DeviceStore(new InMemoryRedisClient()), 'veepoo-ble'))->markOffline('cfe908067329', self::DEVICE);

        self::assertSame(['offline'], array_column(array_column($mqtt->statuses, 'payload'), 'state'));
    }
}
