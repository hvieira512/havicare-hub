<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Gateway;

use Hub\Command\Downlink\RedisPendingDownlinkQueue;
use Hub\Ingress\Mqtt\Gateway\GattDownlinks;
use Hub\Ingress\Mqtt\Veepoo\DownlinkDispatcher;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** A mesma ordem a dois aparelhos tem a mesma chave; o travão de reenvio é de cada um. */
final class ResendThrottleTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const TOPIC = 'havicare-hub/null/0/gw/+/raw';

    public function testTheSecondBraceletGetsTheSameCommandAtOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $dispatcher = new DownlinkDispatcher(self::queued('config:find_device', '9f69c4866e6c', '3a8dda1bc715'), $mqtt, null, self::TOPIC);

        $dispatcher->dispatchPending('9f69c4866e6c', self::GATEWAY);
        $dispatcher->dispatchPending('3a8dda1bc715', self::GATEWAY);

        self::assertSame(['9f69c4866e6c', '3a8dda1bc715'], self::recipients($mqtt));
    }

    public function testTheSecondGattDeviceGetsTheSameCommandAtOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $downlinks = new GattDownlinks(
            self::queued('calibrateClock', '748b34837b1e', '748b3483cda4'),
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

    /** Quem integra sabe que a ordem saiu pelo gateway, como sabe de uma que sai por socket. */
    public function testASendThroughTheGatewayIsAnnounced(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue('cfe908067329', 'weightUnit', ['id' => 'c-1'], 300);
        $store = new DeviceStore(new InMemoryRedisClient());
        $store->recordCommand('cfe908067329', 'c-1', ['status' => 'queued', 'nativeType' => 'weightUnit']);
        $announced = [];
        $store->commands->announceTo(static function (string $imei, string $type) use (&$announced): void {
            $announced[] = $type;
        });

        (new GattDownlinks($queue, new RecordingHubMqttBridge(), $store, self::TOPIC, static fn (): array => [['char' => 'fff1', 'hex' => '00']]))
            ->dispatchPending('cfe908067329', self::GATEWAY, 61, 0);

        self::assertSame(['device.downlink.sent'], $announced);
    }

    /** Sem o aparelho ligado não houve tentativa: o comando volta à fila, e não conta para o limite. */
    public function testANotConnectedReplyPutsTheCommandBackInTheQueue(): void
    {
        [$store, $downlinks, $mqtt] = $this->gattWithStore(static fn (): array => [['char' => 'fff1', 'hex' => '00']]);
        $downlinks->dispatchPending('cfe908067329', self::GATEWAY, 61, 0);

        $downlinks->delivered('cfe908067329', (string)$mqtt->gatewayCommands[0]['payload']['dedupeKey'], false, 'not_connected');

        self::assertSame('queued', $store->findCommand('c-1')['command']['status'] ?? null);
    }

    /** O código do erro é uma enumeração; o texto do motivo segue à parte. */
    public function testAnOrderThatCannotBeBuiltFailsWithACode(): void
    {
        [$store, $downlinks] = $this->gattWithStore(static fn (): array => throw new \InvalidArgumentException('unit must be one of kg, lb'));

        $downlinks->dispatchPending('cfe908067329', self::GATEWAY, 61, 0);

        $command = $store->findCommand('c-1')['command'] ?? [];
        self::assertSame(['failed', 'invalid_payload', 'unit must be one of kg, lb'], [$command['status'] ?? null, $command['error'] ?? null, $command['errorMessage'] ?? null]);
    }

    /** Duas ordens com a mesma operação são dois pedidos: a confirmação de uma fecha essa, e não a outra. */
    public function testABraceletConfirmationClosesItsOwnRequest(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue('9f69c4866e6c', 'find_device', ['id' => 'c-on', 'command' => 'find_device', 'payload' => ['enabled' => true]], 300);
        $queue->enqueue('9f69c4866e6c', 'find_device', ['id' => 'c-off', 'command' => 'find_device', 'payload' => ['enabled' => false]], 300);
        $store = new DeviceStore(new InMemoryRedisClient());
        $store->recordCommand('9f69c4866e6c', 'c-on', ['status' => 'queued', 'nativeType' => 'find_device']);
        $store->recordCommand('9f69c4866e6c', 'c-off', ['status' => 'queued', 'nativeType' => 'find_device']);
        $first = $queue->pendingFor('9f69c4866e6c')[0];

        (new DownlinkDispatcher($queue, new RecordingHubMqttBridge(), $store, self::TOPIC))->resolvePending('9f69c4866e6c', $first->dedupeKey);

        $closed = (string)($first->command['id'] ?? '');
        $open = $closed === 'c-on' ? 'c-off' : 'c-on';
        self::assertSame(['acked', 'queued'], [$store->findCommand($closed)['command']['status'] ?? null, $store->findCommand($open)['command']['status'] ?? null]);
    }

    /** @return array{DeviceStore, GattDownlinks, RecordingHubMqttBridge} */
    private function gattWithStore(callable $writes): array
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue('cfe908067329', 'weightUnit', ['id' => 'c-1'], 300);
        $store = new DeviceStore(new InMemoryRedisClient());
        $store->recordCommand('cfe908067329', 'c-1', ['status' => 'queued', 'nativeType' => 'weightUnit', 'expectedReplyTypes' => ['torre_unit']]);
        $mqtt = new RecordingHubMqttBridge();

        return [$store, new GattDownlinks($queue, $mqtt, $store, self::TOPIC, $writes), $mqtt];
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

    private static function queued(string $operation, string ...$deviceKeys): RedisPendingDownlinkQueue
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        foreach ($deviceKeys as $deviceKey) {
            $queue->enqueue($deviceKey, $operation, null, 300);
        }

        return $queue;
    }
}
