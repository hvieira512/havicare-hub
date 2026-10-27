<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use Hub\Command\Downlink\RedisPendingDownlinkQueue;
use Hub\Device\HubMqttBridge;
use Hub\Ingress\Tcp\DeviceHubServer;
use Hub\Ingress\Tcp\Wonlex\WonlexAdapter;
use Hub\Location\BeaconDbRequestBuilder;
use Hub\Location\BeaconDbTelemetryEnricher;
use Hub\Location\RedisLocationResolutionCache;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\DeviceStore;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\CallbackLocationProvider;
use Tests\Support\Doubles\FakeConnection;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\WavFixture;

use function React\Promise\resolve;

final class DeviceHubMqttContractTest extends TestCase
{
    private Whitelist $whitelist;

    protected function setUp(): void
    {
        $this->whitelist = IngressFixtures::whitelist([
            '865028000000308' => ['supplier' => 'Vivistar', 'model' => 'VIVISTAR-CARE'],
            '868705080300697' => ['supplier' => 'Wonlex', 'model' => 'HW20PRO'],
            '637507597567372' => [
                'supplier' => '4P Touch',
                'model' => '4P-TOUCH',
                'deviceId' => '7597567372',
                'licenseId' => '1001',
                'company' => 'hitcare',
            ],
        ]);
    }

    public function testRejectedDevicePublishesErrorStatusAndRejectedEvent(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(
                '865028000000999',
                'vivistar-iw',
                '',
                '',
                'device_not_authorized'
            );
        $hub = new DeviceHubServer(
            $this->whitelist,
            $mqtt,
            deviceStore: $store
        );
        $connection = new FakeConnection(1);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000999#');

        self::assertTrue($connection->closed);
        self::assertCount(1, $mqtt->statuses);
        self::assertCount(1, $mqtt->events);
        self::assertCount(0, $mqtt->raw);

        self::assertSame('865028000000999', $mqtt->statuses[0][0]);
        self::assertSame('error', $mqtt->statuses[0][1]['state']);
        self::assertSame('device_not_authorized', $mqtt->statuses[0][1]['error']['code']);
        // Uma recusa é um acontecimento, não um estado: o `error` não fica retido, senão o
        // próximo subscritor recebia a rejeição de um aparelho que já nem está a tentar ligar.
        self::assertFalse($mqtt->statuses[0][2], 'o status de erro da rejeição não é retido');
        self::assertSame('device.rejected', $mqtt->events[0][1]['type']);
        self::assertSame('device_not_authorized', $mqtt->events[0][1]['error']['code']);
    }

    /** Rejeição silenciosa: fecha-se a ligação sem notificação na dashboard nem estado ou evento no broker. */
    public function testDenylistedDeviceIsRejectedSilently(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::never())->method('recordRejectedDevice');

        $denylist = new Denylist();
        $denylist->block('865028000000999');

        $hub = new DeviceHubServer(
            $this->whitelist,
            $mqtt,
            deviceStore: $store,
            denylist: $denylist,
        );
        $connection = new FakeConnection(1);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000999#');

        self::assertTrue($connection->closed, 'a ligação fecha na mesma');
        self::assertCount(0, $mqtt->statuses, 'sem estado no broker');
        self::assertCount(0, $mqtt->events, 'sem evento no broker');
    }

    /** Um relógio por registar repete o login sem parar: a base leva uma escrita por minuto, e o broker todas. */
    public function testARepeatedRejectionIsRecordedOncePerMinute(): void
    {
        $now = 1_000;
        $mqtt = new ContractRecordingHubMqttBridge();
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::exactly(2))->method('recordRejectedDevice');
        $hub = new DeviceHubServer($this->whitelist, $mqtt, deviceStore: $store, clock: static function () use (&$now): int {
            return $now;
        });

        foreach ([1_000, 1_030, 1_060] as $index => $now) {
            $connection = new FakeConnection($index + 1, "tcp://198.51.100.{$index}:4000");
            $hub->onOpen($connection);
            $hub->onMessage($connection, 'IWAP00865028000000999#');
        }

        self::assertCount(3, $mqtt->events);
    }

    /** Um varredor inventa um IMEI por ligação: de cada endereço sai uma escrita por minuto. */
    public function testRejectionsFromOneAddressAreRecordedOncePerMinute(): void
    {
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::once())->method('recordRejectedDevice');
        $hub = new DeviceHubServer($this->whitelist, new ContractRecordingHubMqttBridge(), deviceStore: $store, clock: static fn (): int => 1_000);

        foreach (['865028000000991', '865028000000992', '865028000000993'] as $index => $imei) {
            $connection = new FakeConnection($index + 1, 'tcp://198.51.100.7:' . (4000 + $index));
            $hub->onOpen($connection);
            $hub->onMessage($connection, "IWAP00{$imei}#");
        }
    }

    public function testNotificationPersistenceFailureDoesNotInterruptDeviceRejection(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $store = $this->createStub(DeviceStoreContract::class);
        $store->method('recordRejectedDevice')
            ->willThrowException(new \RuntimeException('Database unavailable'));
        $hub = new DeviceHubServer(
            $this->whitelist,
            $mqtt,
            deviceStore: $store
        );
        $connection = new FakeConnection(11);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000999#');

        self::assertTrue($connection->closed);
        self::assertCount(1, $mqtt->statuses);
        self::assertCount(1, $mqtt->events);
    }

    public function testDisconnectPublishesRetainedOfflineStatusAndDisconnectedEvent(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $now = 1_000;
        $hub = new DeviceHubServer($this->whitelist, $mqtt, clock: static function () use (&$now): int {
            return $now;
        });
        $connection = new FakeConnection(7);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000308#');
        $hub->onClose($connection);
        $now += 60;
        $hub->announceClosedConnections();

        // O `online` e depois o `offline`, ambos estado, ambos retidos.
        self::assertCount(2, $mqtt->statuses);
        self::assertSame('online', $mqtt->statuses[0][1]['state']);
        self::assertTrue($mqtt->statuses[0][2], 'o online fica retido');
        self::assertSame('865028000000308', $mqtt->statuses[1][0]);
        self::assertSame('offline', $mqtt->statuses[1][1]['state']);
        self::assertTrue($mqtt->statuses[1][2], 'o offline é estado e fica retido');

        $disconnected = array_values(array_filter(
            $mqtt->events,
            static fn (array $event): bool => ($event[1]['type'] ?? '') === 'device.disconnected'
        ));
        self::assertCount(1, $disconnected, 'o desligamento publica um device.disconnected');
        self::assertSame('865028000000308', $disconnected[0][0]);
        self::assertArrayNotHasKey('severity', $disconnected[0][1]);
    }

    public function testAConnectionCarriesNoSeverity(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $connection = new FakeConnection(7);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000308#');

        self::assertSame('device.connected', $mqtt->events[0][1]['type']);
        self::assertArrayNotHasKey('severity', $mqtt->events[0][1]);
    }

    /** Um socket fechado espera 60 s por uma ligação nova antes de se dar o aparelho por desligado. */
    public function testASocketClosedForLessThanAMinuteIsNotAnnounced(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $now = 1_000;
        $hub = new DeviceHubServer($this->whitelist, $mqtt, clock: static function () use (&$now): int {
            return $now;
        });
        $first = new FakeConnection(7);
        $hub->onOpen($first);
        $hub->onMessage($first, 'IWAP00865028000000308#');

        $hub->onClose($first);
        $now += 59;
        $hub->announceClosedConnections();
        self::assertSame(['device.connected'], array_column(array_column($mqtt->events, 1), 'type'));
        self::assertTrue($hub->isConnected('865028000000308'), 'a manutenção não o desliga enquanto espera');

        $second = new FakeConnection(8);
        $hub->onOpen($second);
        $hub->onMessage($second, 'IWAP00865028000000308#');
        $now += 60;
        $hub->announceClosedConnections();

        self::assertSame(['device.connected'], array_column(array_column($mqtt->events, 1), 'type'));
        self::assertSame(['online', 'online'], array_column(array_column($mqtt->statuses, 1), 'state'));
    }

    public function testOfflineDownlinkPublishesDroppedEvent(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);

        self::assertFalse($hub->sendDownlink('865028000000308', 'IWBP03#'));
        $hub->reportDownlinkDropped('865028000000308', 'device_offline');

        self::assertCount(1, $mqtt->events);
        self::assertSame('device.downlink.dropped', $mqtt->events[0][1]['type']);
        self::assertSame('Vivistar', $mqtt->events[0][1]['device']['supplier']);
        self::assertSame('VIVISTAR-CARE', $mqtt->events[0][1]['device']['model']);
        self::assertSame('device_offline', $mqtt->events[0][1]['error']['code']);
        self::assertCount(0, $mqtt->statuses);
        self::assertCount(0, $mqtt->raw);
    }

    /** Quando o relógio volta, a ordem sem resposta prevista fica enviada, e a com resposta fica à espera e só se repete após o prazo. */
    public function testAQueuedCommandLeavesWhenTheWatchReconnects(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $store = new DeviceStore(new InMemoryRedisClient());
        $hub = new DeviceHubServer($this->whitelist, new ContractRecordingHubMqttBridge(), downlinkQueue: $queue, deviceStore: $store, downlinkQueueTtlSeconds: 300);
        $adapter = new WonlexAdapter();
        foreach (['c-notice' => [], 'c-hr' => ['upHeartRate']] as $id => $replies) {
            $bytes = $adapter->encodeOutgoing(['type' => $id === 'c-notice' ? 'dnNoReply' : 'dnHeartRate', 'ident' => 1, 'ref' => 's:down', 'imei' => '868705080300697', 'data' => []]);
            $store->recordCommand('868705080300697', $id, ['status' => 'queued', 'expectedReplyTypes' => $replies, 'nextRetryAt' => '2000-01-01T00:00:00Z']);
            $hub->queueDownlink('868705080300697', $bytes, ['id' => $id]);
        }

        $connection = new FakeConnection(9);
        $hub->onOpen($connection);
        $hub->onMessage($connection, $adapter->encodeOutgoing(['type' => 'login', 'imei' => '868705080300697', 'data' => ['deviceModel' => 'IGNORED']]));

        self::assertSame('sent', $store->findCommand('c-notice')['command']['status'] ?? null);
        self::assertSame('waiting', $store->findCommand('c-hr')['command']['status'] ?? null);
        self::assertGreaterThan(time(), strtotime((string)($store->findCommand('c-hr')['command']['nextRetryAt'] ?? '')));
    }

    public function testOfflineDownlinkQueuesLatestCommandPerDeviceAndNativeType(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $hub = new DeviceHubServer(
            $this->whitelist,
            $mqtt,
            downlinkQueue: $queue,
            downlinkQueueTtlSeconds: 300
        );
        $adapter = new WonlexAdapter();

        self::assertTrue($hub->queueDownlink('868705080300697', $adapter->encodeOutgoing([
            'type' => 'dnHeartRate',
            'ident' => 111111,
            'ref' => 's:down',
            'imei' => '868705080300697',
            'data' => ['type' => 'dnHeartRate', 'imei' => '868705080300697'],
        ])));
        self::assertTrue($hub->queueDownlink('868705080300697', $adapter->encodeOutgoing([
            'type' => 'dnHeartRate',
            'ident' => 222222,
            'ref' => 's:down',
            'imei' => '868705080300697',
            'data' => ['type' => 'dnHeartRate', 'imei' => '868705080300697'],
        ])));
        self::assertTrue($hub->queueDownlink('868705080300697', $adapter->encodeOutgoing([
            'type' => 'dnLocation',
            'ident' => 333333,
            'ref' => 's:down',
            'imei' => '868705080300697',
            'data' => ['type' => 'dnLocation', 'imei' => '868705080300697'],
        ])));

        self::assertCount(3, $mqtt->events);
        self::assertSame('device.downlink.queued', $mqtt->events[0][1]['type']);
        self::assertSame('dnHeartRate', $mqtt->events[0][1]['command']['nativeType']);
        self::assertSame('device.downlink.queued', $mqtt->events[2][1]['type']);
        $pending = $queue->pendingFor('868705080300697');
        self::assertCount(2, $pending);
        self::assertSame(300, $pending[0]->expiresAt - $pending[0]->queuedAt);
    }

    /** A manutenção renova a entrada de minuto a minuto, mas o anúncio é do pedido e sai uma vez. */
    public function testRenewingAQueuedCommandDoesNotAnnounceItAgain(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $hub = new DeviceHubServer($this->whitelist, $mqtt, downlinkQueue: $queue, downlinkQueueTtlSeconds: 300);
        $context = ['id' => 'cmd-44807b18', 'command' => 'BPXY', 'nativeType' => 'BPXY', 'protocol' => 'vivistar-iw'];

        for ($renewal = 0; $renewal < 3; $renewal++) {
            self::assertTrue($hub->queueDownlink('865028000000308', 'IWBPXY,865028000000308,080835#', $context));
        }

        self::assertSame(['device.downlink.queued'], array_map(static fn(array $event): string => $event[1]['type'], $mqtt->events));
        self::assertCount(1, $queue->pendingFor('865028000000308'));
    }

    /** Quem entrega fora do socket (a cloud dos radares) sabe logo que há trabalho, e também nas renovações. */
    public function testAQueuedCommandIsAnnouncedToWhoeverDeliversIt(): void
    {
        $queued = [];
        $hub = new DeviceHubServer(
            $this->whitelist,
            new ContractRecordingHubMqttBridge(),
            downlinkQueue: new RedisPendingDownlinkQueue(new InMemoryRedisClient()),
            downlinkQueued: static function (string $imei) use (&$queued): void {
                $queued[] = $imei;
            },
        );

        $hub->queueDownlink('865028000000308', 'IWBPXY,865028000000308,080835#', ['id' => 'cmd-1']);
        $hub->queueDownlink('865028000000308', 'IWBPXY,865028000000308,080835#', ['id' => 'cmd-1']);

        self::assertSame(['865028000000308', '865028000000308'], $queued);
    }

    public function testOfflineDownlinkDropsWithQueueUnavailableWhenRedisFails(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $redis = new InMemoryRedisClient();
        $redis->failOn = 'hset';
        $queue = new RedisPendingDownlinkQueue($redis);
        $hub = new DeviceHubServer(
            $this->whitelist,
            $mqtt,
            downlinkQueue: $queue
        );

        self::assertFalse($hub->queueDownlink('865028000000308', 'IWBPXY,865028000000308,080835#'));

        self::assertCount(1, $mqtt->events);
        self::assertSame('device.downlink.dropped', $mqtt->events[0][1]['type']);
        self::assertSame('queue_unavailable', $mqtt->events[0][1]['error']['code']);
        self::assertSame('BPXY', $mqtt->events[0][1]['command']['nativeType']);
    }

    public function testDeviceClaimedModelIsIgnoredForAuthorizationAndMqttMetadata(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $connection = new FakeConnection(2);
        $adapter = new WonlexAdapter();

        $hub->onOpen($connection);
        $hub->onMessage($connection, $adapter->encodeOutgoing([
            'type' => 'login',
            'ident' => 614377,
            'imei' => '868705080300697',
            'data' => [
                'deviceModel' => 'DEVICE-CLAIMED-MODEL',
            ],
        ]));

        self::assertFalse($connection->closed);
        self::assertCount(1, $mqtt->statuses);
        self::assertSame('online', $mqtt->statuses[0][1]['state']);
        self::assertSame('Wonlex', $mqtt->statuses[0][1]['device']['supplier']);
        self::assertSame('HW20PRO', $mqtt->statuses[0][1]['device']['model']);
        self::assertSame('Wonlex', $mqtt->events[0][1]['device']['supplier']);
        self::assertSame('HW20PRO', $mqtt->raw[0][1]['device']['model']);
    }

    public function testFourPTouchAssociationUpdateChangesMqttTopicPrefixOnNextMessage(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $whitelist = $this->whitelist;
        $hub = new DeviceHubServer($whitelist, $mqtt);
        $connection = new FakeConnection(9);

        $hub->onOpen($connection);
        $hub->onMessage($connection, '[3G*7597567372*000D*LK,50,100,100]');

        self::assertContains(
            'hitcare/1001/watch/637507597567372/raw',
            $mqtt->rawTopics,
        );
        self::assertContains(
            'hitcare/1001/watch/637507597567372/status',
            $mqtt->statusTopics,
        );

        $whitelist->updateAssociation('637507597567372', 'havicare', '1');

        $hub->onMessage($connection, '[3G*7597567372*000D*LK,50,100,100]');

        self::assertContains(
            'havicare/1/watch/637507597567372/raw',
            $mqtt->rawTopics,
        );
        self::assertSame(
            'havicare/1/watch/637507597567372/raw',
            $mqtt->rawTopics[array_key_last($mqtt->rawTopics)],
        );
    }

    public function testPendingDownlinksFlushAfterDeviceLogin(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $hub = new DeviceHubServer(
            $this->whitelist,
            $mqtt,
            downlinkQueue: $queue
        );
        $connection = new FakeConnection(6);
        $adapter = new WonlexAdapter();

        $queuedBytes = $adapter->encodeOutgoing([
            'type' => 'dnHeartRate',
            'ident' => 444444,
            'ref' => 's:down',
            'imei' => '868705080300697',
            'data' => ['type' => 'dnHeartRate', 'imei' => '868705080300697'],
        ]);
        $queue->enqueue('868705080300697', $queuedBytes, [
            'nativeType' => 'dnHeartRate',
            'protocol' => 'wonlex-json',
            'ident' => 444444,
        ], 300);

        $hub->onOpen($connection);
        $hub->onMessage($connection, $adapter->encodeOutgoing([
            'type' => 'login',
            'imei' => '868705080300697',
            'data' => ['deviceModel' => 'IGNORED'],
        ]));

        self::assertCount(2, $connection->sent);
        self::assertSame('login', $adapter->decodeIncoming($connection->sent[0])['type']);
        self::assertSame('dnHeartRate', $adapter->decodeIncoming($connection->sent[1])['type']);
        self::assertSame('device.downlink.sent', $mqtt->events[1][1]['type']);
        self::assertSame('dnHeartRate', $mqtt->events[1][1]['command']['nativeType']);
        self::assertCount(0, $queue->pendingFor('868705080300697'));
    }

    /** O pedido encontra-se pelo `id`, porque o tipo da trama não é o nome com que foi registado. */
    public function testAQueuedRequestWaitsForTheReplyOnceItIsFlushed(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $store = new \Hub\State\DeviceStore(new InMemoryRedisClient(), 100);
        $hub = new DeviceHubServer($this->whitelist, new ContractRecordingHubMqttBridge(), downlinkQueue: $queue, deviceStore: $store);
        $connection = new FakeConnection(7);
        $adapter = new WonlexAdapter();

        $store->recordCommand('868705080300697', 'req-1', [
            'status' => 'queued',
            'nativeType' => 'heartRateRequest',
            'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
        $queue->enqueue('868705080300697', $adapter->encodeOutgoing([
            'type' => 'dnHeartRate',
            'ident' => 555555,
            'ref' => 's:down',
            'imei' => '868705080300697',
            'data' => ['type' => 'dnHeartRate', 'imei' => '868705080300697'],
        ]), ['nativeType' => 'dnHeartRate', 'protocol' => 'wonlex-json', 'id' => 'req-1'], 300);

        $hub->onOpen($connection);
        $hub->onMessage($connection, $adapter->encodeOutgoing([
            'type' => 'login',
            'imei' => '868705080300697',
            'data' => ['deviceModel' => 'IGNORED'],
        ]));

        $command = $store->findCommand('req-1');
        self::assertSame('waiting', $command['command']['status'] ?? $command['status'] ?? null);
    }

    public function testAuthenticatedMeasurementPublishesDecodedEventWithoutDebugFields(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt, $this->commercialResolver());
        $connection = new FakeConnection(3);
        $adapter = new WonlexAdapter();

        $hub->onOpen($connection);
        $hub->onMessage($connection, $adapter->encodeOutgoing([
            'type' => 'login',
            'imei' => '868705080300697',
            'data' => ['deviceModel' => 'IGNORED'],
        ]));
        $hub->onMessage($connection, $adapter->encodeOutgoing([
            'type' => 'upHeartRate',
            'ident' => 274611,
            'ref' => 'w:update',
            'imei' => '868705080300697',
            'data' => ['heartRate' => 72],
        ]));

        self::assertCount(3, $mqtt->raw);
        self::assertCount(1, $mqtt->events);
        self::assertCount(1, $mqtt->telemetry);
        self::assertSame('device.connected', $mqtt->events[0][1]['type']);
        self::assertSame('Wonlex HW20 Pro', $mqtt->events[0][1]['device']['commercialName']);
        self::assertSame('heart_rate', $mqtt->telemetry[0][1]['type']);
        self::assertArrayNotHasKey('schemaVersion', $mqtt->telemetry[0][1]);
        self::assertSame(['bpm' => 72], $mqtt->telemetry[0][1]['data']);
        self::assertSame('wonlex-json', $mqtt->telemetry[0][1]['source']['protocol']);
        self::assertSame('upHeartRate', $mqtt->telemetry[0][1]['source']['nativeType']);
        self::assertSame('Wonlex HW20 Pro', $mqtt->telemetry[0][1]['device']['commercialName']);
        self::assertArrayNotHasKey('debug', $mqtt->telemetry[0][1]);

        self::assertSame('Wonlex HW20 Pro', $mqtt->raw[0][1]['device']['commercialName']);
        self::assertSame('downlink', $mqtt->raw[2][1]['direction']);
        self::assertSame('Wonlex HW20 Pro', $mqtt->raw[2][1]['device']['commercialName']);
        $ack = $adapter->decodeIncoming(base64_decode($mqtt->raw[2][1]['debug']['encoded'], true));
        self::assertIsArray($ack);
        self::assertSame('upHeartRate', $ack['type']);
        self::assertSame(274611, $ack['ident']);
        self::assertSame('s:reply', $ack['ref']);
        self::assertSame('868705080300697', $ack['imei']);
    }

    /** O id do pedido liga o envio ao desfecho que vem depois. */
    public function testASubmittedDownlinkCarriesTheRequestId(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $connection = new FakeConnection(4);
        $adapter = new WonlexAdapter();
        $hub->onOpen($connection);
        $hub->onMessage($connection, $adapter->encodeOutgoing(['type' => 'login', 'imei' => '868705080300697', 'data' => ['deviceModel' => 'IGNORED']]));

        $hub->submitDownlink('868705080300697', $adapter->encodeOutgoing([
            'type' => 'dnHeartRate', 'ident' => 1, 'ref' => 's:down', 'imei' => '868705080300697', 'data' => ['type' => 'dnHeartRate'],
        ]), ['id' => 'c-1']);
        $hub->submitDownlink('865028000000308', 'IWBP03#', ['id' => 'c-2']);

        $downlinks = array_values(array_filter($mqtt->events, static fn (array $event): bool => str_starts_with((string)($event[1]['type'] ?? ''), 'device.downlink.')));
        self::assertSame(['device.downlink.sent', 'device.downlink.dropped'], array_map(static fn (array $event): string => $event[1]['type'], $downlinks));
        self::assertSame(['c-1', 'c-2'], array_map(static fn (array $event): string => (string)($event[1]['command']['id'] ?? ''), $downlinks));
    }

    public function testOnlineDownlinkPublishesCommandMetadata(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $connection = new FakeConnection(4);
        $adapter = new WonlexAdapter();

        $hub->onOpen($connection);
        $hub->onMessage($connection, $adapter->encodeOutgoing([
            'type' => 'login',
            'imei' => '868705080300697',
            'data' => ['deviceModel' => 'IGNORED'],
        ]));

        self::assertTrue($hub->sendDownlink('868705080300697', $adapter->encodeOutgoing([
            'type' => 'dnHeartRate',
            'ident' => 123456,
            'ref' => 's:down',
            'imei' => '868705080300697',
            'data' => ['type' => 'dnHeartRate', 'imei' => '868705080300697'],
        ])));

        self::assertSame('device.downlink.sent', $mqtt->events[1][1]['type']);
        self::assertSame([
            'nativeType' => 'dnHeartRate',
            'protocol' => 'wonlex-json',
            'ident' => 123456,
        ], $mqtt->events[1][1]['command']);
    }

    /** Um relógio celular reconecta antes de o socket antigo fechar, e ficar online não pode arrastar o `device.disconnected` anterior. */
    public function testReconnectDoesNotEmitADisconnectForTheSupersededSession(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $now = 1_000;
        $hub = new DeviceHubServer($this->whitelist, $mqtt, clock: static function () use (&$now): int {
            return $now;
        });

        $first = new FakeConnection(5);
        $hub->onOpen($first);
        $hub->onMessage($first, 'IWAP00865028000000308#');

        // A primeira sessão envelhece, e só então o relógio reconecta noutro socket.
        $now += 2 * 480 + 30;
        $second = new FakeConnection(6);
        $hub->onOpen($second);
        $hub->onMessage($second, 'IWAP00865028000000308#');

        // A varredura corre com a antiga já para lá do prazo: fechada na reautenticação, não
        // expira nem publica um disconnect.
        $hub->expireIdleConnections();

        $disconnects = array_filter(
            $mqtt->events,
            static fn(array $event): bool => ($event[1]['type'] ?? '') === 'device.disconnected'
        );
        self::assertSame([], $disconnects, 'a reconexão não gera um disconnect espúrio');
        self::assertTrue($first->closed, 'o socket anterior é fechado na hora, não deixado a expirar');

        // E o downlink continua a chegar ao relógio vivo -- vai para a ligação nova.
        self::assertTrue($hub->sendDownlink('865028000000308', 'IWBPXY,865028000000308,080835#'));
        self::assertNotEmpty($second->sent, 'a ligação activa recebe o comando');
    }

    /** Uma ligação cuja sessão já saiu do registo continua a receber o comando. */
    public function testADownlinkReachesAConnectionWhoseSessionIsGone(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $watch = new FakeConnection(5);
        $hub->onOpen($watch);
        $hub->onMessage($watch, 'IWAP00865028000000308#');

        // Outra ligação com o mesmo `resourceId` abre e fecha, e leva a sessão do relógio.
        $other = new FakeConnection(5);
        $hub->onOpen($other);
        $hub->onClose($other);

        self::assertTrue($hub->sendDownlink('865028000000308', 'IWBP03#'));
        self::assertSame('IWBP03#', end($watch->sent));
    }

    public function testVivistarOnlineDownlinkPublishesCommandMetadata(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $connection = new FakeConnection(5);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000308#');

        self::assertTrue($hub->sendDownlink('865028000000308', 'IWBPXY,865028000000308,080835#'));

        self::assertSame('device.downlink.sent', $mqtt->events[1][1]['type']);
        self::assertSame([
            'nativeType' => 'BPXY',
            'protocol' => 'vivistar-iw',
            'ident' => '080835',
        ], $mqtt->events[1][1]['command']);
    }

    public function testFourPTouchTakePillsOnlineDownlinkPublishesAmrConvertedAudioMetadata(): void
    {
        if (!\Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder::supportsVoiceTranscoding()) {
            self::markTestSkipped('ffmpeg with AMR-NB support is not available');
        }
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $connection = new FakeConnection(8);
        $adapter = new \Hub\Ingress\Tcp\FourPTouch\FourPTouchAdapter();

        $hub->onOpen($connection);
        $hub->onMessage($connection, '[3G*7597567372*000D*LK,50,100,100]');

        $payload = \Hub\Command\DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                'time' => '11:25',
                'enabled' => true,
                'frequency' => 3,
                'custom' => '1010101',
            ],
            'number' => 3,
            'reminderText' => 'meds',
            'voiceData' => 'data:audio/wav;base64,' . WavFixture::silenceBase64(),
            'voiceMimeType' => 'audio/wav',
        ]);
        $bytes = \Hub\Command\DeviceCommandCatalog::buildDownlink(
            'four-p-touch',
            '7597567372',
            $payload['command'],
            $payload['payload'],
            ['deviceId' => '']
        );

        self::assertTrue($hub->sendDownlink('637507597567372', $bytes));
        self::assertSame('device.downlink.sent', $mqtt->events[1][1]['type']);
        self::assertSame('TAKEPILLS', $mqtt->events[1][1]['command']['nativeType'] ?? null);
        self::assertSame('four-p-touch', $mqtt->events[1][1]['command']['protocol'] ?? null);
        self::assertSame('downlink', $mqtt->raw[1][1]['direction'] ?? null);
        self::assertSame('base64', $mqtt->raw[1][1]['debug']['encoding'] ?? null);
        self::assertSame(base64_encode($bytes), $mqtt->raw[1][1]['debug']['payload'] ?? null);
        self::assertStringContainsString('TAKEPILLS,11:25-1-3-1010101,1,006D006500640073,', $bytes);
        self::assertStringStartsWith("#!AMR\n", $payload['payload']['fields'][3] ?? '');
    }

    public function testFourPTouchTakePillsWithVoiceIsQueuedForAWatchThatIsNotConnected(): void
    {
        if (!\Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder::supportsVoiceTranscoding()) {
            self::markTestSkipped('ffmpeg with AMR-NB support is not available');
        }
        $mqtt = new ContractRecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $hub = new DeviceHubServer($this->whitelist, $mqtt, downlinkQueue: $queue);

        $payload = \Hub\Command\DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                'time' => '11:25',
                'enabled' => true,
                'frequency' => 3,
                'custom' => '1010101',
            ],
            'number' => 3,
            'reminderText' => 'meds',
            'voiceData' => 'data:audio/wav;base64,' . WavFixture::silenceBase64(),
            'voiceMimeType' => 'audio/wav',
        ]);
        $bytes = \Hub\Command\DeviceCommandCatalog::buildDownlink(
            'four-p-touch',
            '7597567372',
            $payload['command'],
            $payload['payload'],
            ['deviceId' => '']
        );

        $status = $hub->submitDownlink('637507597567372', $bytes, [
            'operationId' => 'bb7b0f4c1d5f4f2f9d1a6b0c3e8d7a55',
            'command' => 'TAKEPILLS',
            'payload' => $payload['payload'],
        ]);

        self::assertSame('queued', $status);
        $pending = $queue->pendingFor('637507597567372');
        self::assertCount(1, $pending);
        self::assertSame($bytes, $pending[0]->bytes);
        self::assertSame('device.downlink.queued', $mqtt->events[0][1]['type']);
        self::assertNotFalse(json_encode($mqtt->events[0][1]));
    }

    public function testFourPTouchRejectedTakePillsReplyIsPassedToCommandLifecycle(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $store = $this->createStub(DeviceStoreContract::class);
        $replies = [];
        $store->method('markCommandReply')->willReturnCallback(
            static function (...$arguments) use (&$replies): void {
                $replies[] = $arguments;
            }
        );
        $hub = new DeviceHubServer(
            $this->whitelist,
            $mqtt,
            deviceStore: $store,
        );
        $connection = new FakeConnection(18);

        $hub->onOpen($connection);
        $hub->onMessage($connection, '[3G*7597567372*000D*LK,50,100,100]');
        $hub->onMessage($connection, '[3G*7597567372*000B*TAKEPILLS,0]');

        $takePillsReply = $replies[array_key_last($replies)] ?? [];
        self::assertSame('TAKEPILLS', $takePillsReply[1] ?? null);
        self::assertFalse($takePillsReply[4] ?? null);
    }

    public function testVivistarTelemetryPacketsReceiveProtocolAck(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $connection = new FakeConnection(7);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000308#');
        $hub->onMessage($connection, 'IWAP49,72#');

        self::assertCount(2, $connection->sent);
        self::assertStringStartsWith('IWBP00,', $connection->sent[0]);
        self::assertStringEndsWith('#', $connection->sent[0]);
        self::assertSame('IWBP49#', $connection->sent[1]);
    }

    public function testWatchAlarmIsPublishedAsAnEventAndTheSiblingLocationStaysTelemetry(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $connection = new FakeConnection(21);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000308#');
        $hub->onMessage(
            $connection,
            'IWAP10080524A2232.9806N11404.9355E000.1061830323.8706000908000602,460,0,9520,3671,'
            . '06,zh-cn,00,HOME|74-DE-2B-44-88-8C|97#'
        );

        $byType = static fn (array $published, string $type): array => array_values(array_filter(
            $published,
            static fn (array $entry): bool => ($entry[1]['type'] ?? null) === $type,
        ));

        $falls = $byType($mqtt->events, 'fall');
        self::assertCount(1, $falls);
        self::assertSame(['confirmed' => true], $falls[0][1]['data']);
        self::assertSame('AP10', $falls[0][1]['source']['nativeType']);
        self::assertArrayNotHasKey('schemaVersion', $falls[0][1]);

        self::assertSame([], $byType($mqtt->telemetry, 'fall'));

        $locations = $byType($mqtt->telemetry, 'location');
        self::assertCount(1, $locations);
        self::assertSame('alarm', $locations[0][1]['data']['reportKind']);
    }

    /** O 4P Touch repete a bateria fraca em cada posição: sai um `low_battery` quando acende, e outro só depois de apagar. */
    public function testARepeatedLowBatteryConditionRaisesTheEventOncePerDischarge(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer($this->whitelist, $mqtt);
        $connection = new FakeConnection(22);
        $position = static function (string $status, int $battery): string {
            $content = 'UD_LTE,240617,101530,V,0.0,N,0.0,E,0.0,0,0,0,55,' . $battery . ',0,0,' . $status . ',0,0,268,01';

            return sprintf('[3G*7597567372*%04X*%s]', strlen($content), $content);
        };

        $hub->onOpen($connection);
        $hub->onMessage($connection, '[3G*7597567372*000D*LK,50,100,100]');
        $hub->onMessage($connection, $position('00000001', 12));
        $hub->onMessage($connection, $position('00000001', 11));
        $lowBattery = static fn (): array => array_values(array_filter(
            $mqtt->events,
            static fn (array $entry): bool => ($entry[1]['type'] ?? null) === 'low_battery',
        ));

        self::assertCount(1, $lowBattery());
        self::assertSame(['percent' => 12], $lowBattery()[0][1]['data']);

        $hub->onMessage($connection, $position('00000000', 60));
        $hub->onMessage($connection, $position('00000001', 13));

        self::assertCount(2, $lowBattery());
    }

    public function testNonGpsLocationIsPublishedWithResolvedCoordinatesInsideData(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            new CallbackLocationProvider(static fn () => resolve([
                'httpStatus' => 200,
                'body' => [
                    'location' => ['lat' => 41.69176, 'lng' => -8.831533],
                    'accuracy' => 300,
                ],
            ])),
            new RedisLocationResolutionCache(new InMemoryRedisClient()),
        );
        $hub = new DeviceHubServer(
            $this->whitelist,
            $mqtt,
            locationTelemetryEnricher: $enricher,
        );
        $connection = new FakeConnection(12);
        $adapter = new WonlexAdapter();

        $hub->onOpen($connection);
        $hub->onMessage($connection, $adapter->encodeOutgoing([
            'type' => 'login',
            'imei' => '868705080300697',
            'data' => ['deviceModel' => 'HW20PRO'],
        ]));
        $hub->onMessage($connection, $adapter->encodeOutgoing([
            'type' => 'upLocation',
            'imei' => '868705080300697',
            'data' => [
                'baseStationType' => 0,
                'positionDataType' => 1,
                'baseStation' => [[
                    'mcc' => 268,
                    'mnc' => 3,
                    'lac' => 180,
                    'cellId' => 194809015,
                ]],
                'Wifi' => [
                    ['ssid' => 'One', 'mac' => 'dc:fe:23:b8:31:73', 'signal' => -44],
                    ['ssid' => 'Two', 'mac' => 'dc:fe:23:36:57:4d', 'signal' => -47],
                ],
            ],
        ]));

        $location = $mqtt->telemetry[array_key_last($mqtt->telemetry)][1];
        self::assertSame('location', $location['type']);
        self::assertArrayNotHasKey('coordinates', $location);
        self::assertTrue($location['data']['hasCoordinates']);
        self::assertSame(41.69176, $location['data']['lat']);
        self::assertSame(-8.831533, $location['data']['lon']);
        self::assertSame(300.0, $location['data']['accuracyMeters']);
    }

    private function commercialResolver(): \Hub\Device\CommercialModelResolver
    {
        return new class extends \Hub\Device\CommercialModelResolver {
            public function __construct()
            {
            }

            public function resolveCommercialName(string $supplier, string $model): string
            {
                if ($supplier === 'Wonlex' && $model === 'HW20PRO') {
                    return 'Wonlex HW20 Pro';
                }
                if ($supplier === 'Vivistar' && $model === 'VIVISTAR-CARE') {
                    return 'Vivistar L08 Pro';
                }
                if ($supplier === '4P Touch' && $model === '4P-TOUCH') {
                    return '4P Touch D46';
                }

                return '';
            }
        };
    }
}

final class ContractRecordingHubMqttBridge extends HubMqttBridge
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $raw = [];
    /** @var list<array{0: string, 1: array<string, mixed>, 2: bool}> */
    public array $statuses = [];
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $events = [];
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $telemetry = [];
    /** @var list<string> */
    public array $rawTopics = [];
    /** @var list<string> */
    public array $statusTopics = [];
    /** @var list<string> */
    public array $eventTopics = [];
    /** @var list<string> */
    public array $telemetryTopics = [];

    public function __construct()
    {
    }

    /** @param array<string, mixed> $payload */
    public function publishRaw(string $imei, array $payload, string $deviceType, int $licenseId = 0, string $company = 'null'): void
    {
        $this->raw[] = [$imei, $payload];
        $this->rawTopics[] = $this->deviceTopic($company, $licenseId, $deviceType, $imei, 'raw');
    }

    /** @param array<string, mixed> $payload */
    public function publishStatus(string $imei, array $payload, bool $retain, string $deviceType, int $licenseId = 0, string $company = 'null'): void
    {
        $this->statuses[] = [$imei, $payload, $retain];
        $this->statusTopics[] = $this->deviceTopic($company, $licenseId, $deviceType, $imei, 'status');
    }

    /** @param array<string, mixed> $payload */
    public function publishEvent(string $imei, array $payload, string $deviceType, int $licenseId = 0, string $company = 'null'): void
    {
        $this->events[] = [$imei, $payload];
        $this->eventTopics[] = $this->deviceTopic($company, $licenseId, $deviceType, $imei, 'events');
    }

    /** @param array<string, mixed> $payload */
    public function publishTelemetry(string $imei, array $payload, string $deviceType, int $licenseId = 0, string $company = 'null'): void
    {
        $this->telemetry[] = [$imei, $payload];
        $this->telemetryTopics[] = $this->deviceTopic($company, $licenseId, $deviceType, $imei, 'telemetry');
    }
}
