<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Gateway;

use Hub\Device\RedisPendingDownlinkQueue;
use Hub\Ingress\Mqtt\Gateway\GattDownlinks;
use Hub\Ingress\Mqtt\Veepoo\DownlinkDispatcher;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\OneShotDownlinkQueue;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** A mesma ordem a dois aparelhos tem a mesma chave; o travão de reenvio é de cada um. */
final class ResendThrottleTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const TOPIC = 'havicare-hub/null/0/gw/+/raw';

    public function testTheSecondBraceletGetsTheSameCommandAtOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $dispatcher = new DownlinkDispatcher(new OneShotDownlinkQueue('config:find_device'), $mqtt, null, self::TOPIC);

        $dispatcher->dispatchPending('9f69c4866e6c', self::GATEWAY);
        $dispatcher->dispatchPending('3a8dda1bc715', self::GATEWAY);

        self::assertSame(['9f69c4866e6c', '3a8dda1bc715'], self::recipients($mqtt));
    }

    public function testTheSecondGattDeviceGetsTheSameCommandAtOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $downlinks = new GattDownlinks(
            new OneShotDownlinkQueue('calibrateClock'),
            $mqtt,
            null,
            self::TOPIC,
            static fn (): array => [['char' => 'fff2', 'hex' => '00']],
        );

        $downlinks->dispatchPending('748b34837b1e', self::GATEWAY, 23, 0);
        $downlinks->dispatchPending('748b3483cda4', self::GATEWAY, 23, 0);

        self::assertSame(['748b34837b1e', '748b3483cda4'], self::recipients($mqtt));
    }

    /** O gateway faz uma ordem de cada vez: a seguinte só sai quando a anterior acaba. */
    public function testAGattDeviceGetsOneCommandAtATimeInOrder(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue('cfe908067329', 'registerScaleUser:u-1', null, 300);
        $queue->enqueue('cfe908067329', 'selectScaleUser:u-1', null, 300);
        $downlinks = new GattDownlinks($queue, $mqtt, null, self::TOPIC, static fn (): array => [['char' => 'fff2', 'hex' => '00']]);

        $downlinks->dispatchPending('cfe908067329', self::GATEWAY, 61, 0);
        $downlinks->dispatchPending('cfe908067329', self::GATEWAY, 61, 0);
        self::assertSame(['registerScaleUser:u-1'], self::operations($mqtt));

        $downlinks->delivered('cfe908067329', (string)$mqtt->gatewayCommands[0]['payload']['dedupeKey'], true, '');
        $downlinks->dispatchPending('cfe908067329', self::GATEWAY, 61, 0);
        self::assertSame(['registerScaleUser:u-1', 'selectScaleUser:u-1'], self::operations($mqtt));
    }

    /** Com o aparelho desligado a ordem espera pela ligação seguinte, como diz o capítulo 22. */
    public function testACommandForADisconnectedDeviceStaysQueued(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue('cfe908067329', 'weightUnit', null, 300);
        $downlinks = new GattDownlinks($queue, $mqtt, null, self::TOPIC, static fn (): array => [['char' => 'fff1', 'hex' => '00']]);
        $downlinks->dispatchPending('cfe908067329', self::GATEWAY, 61, 0);

        $downlinks->delivered('cfe908067329', (string)$mqtt->gatewayCommands[0]['payload']['dedupeKey'], false, 'not_connected');

        self::assertCount(1, $queue->pendingFor('cfe908067329'));
    }

    /** @return list<string> */
    private static function operations(RecordingHubMqttBridge $mqtt): array
    {
        return array_map(static fn (array $c): string => (string)$c['payload']['operation'], $mqtt->gatewayCommands);
    }

    /** @return list<string> */
    private static function recipients(RecordingHubMqttBridge $mqtt): array
    {
        return array_map(static fn (array $c): string => (string)$c['payload']['deviceId'], $mqtt->gatewayCommands);
    }
}
