<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\DeviceHubServer;
use Hub\HubMqttBridge;
use Hub\PendingDownlink;
use Hub\PendingDownlinkQueue;
use Hub\Protocol\Adapter\WonlexAdapter;
use Hub\Registry\Whitelist;
use Hub\ConnectionInterface;
use PHPUnit\Framework\TestCase;

final class DeviceHubMqttContractTest extends TestCase
{
    private string $whitelistPath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-contract-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, json_encode([
            '865028000000308' => ['supplier' => 'Vivistar', 'model' => 'VIVISTAR-CARE'],
            '868705080300697' => ['supplier' => 'Wonlex', 'model' => 'HW20PRO'],
            '637507597567372' => ['supplier' => '4P Touch', 'model' => '4P-TOUCH', 'deviceId' => '7597567372'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (is_file($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
    }

    public function testRejectedDevicePublishesErrorStatusAndRejectedEvent(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);
        $connection = new ContractFakeConnection(1);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000999#');

        self::assertTrue($connection->closed);
        self::assertCount(1, $mqtt->statuses);
        self::assertCount(1, $mqtt->events);
        self::assertCount(0, $mqtt->raw);

        self::assertSame('865028000000999', $mqtt->statuses[0][0]);
        self::assertSame('error', $mqtt->statuses[0][1]['state']);
        self::assertSame('device_not_authorized', $mqtt->statuses[0][1]['error']['code']);
        self::assertSame('device.rejected', $mqtt->events[0][1]['type']);
        self::assertSame('device_not_authorized', $mqtt->events[0][1]['error']['code']);
    }

    public function testOfflineDownlinkPublishesDroppedEvent(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);

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

    public function testOfflineDownlinkQueuesLatestCommandPerDeviceAndNativeType(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $queue = new ContractFakePendingDownlinkQueue();
        $hub = new DeviceHubServer(
            new Whitelist($this->whitelistPath),
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
        self::assertCount(2, $queue->pendingFor('868705080300697'));
        self::assertSame(300, $queue->lastTtl);
    }

    public function testOfflineDownlinkDropsWithQueueUnavailableWhenRedisFails(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $queue = new ContractFakePendingDownlinkQueue();
        $queue->failEnqueue = true;
        $hub = new DeviceHubServer(
            new Whitelist($this->whitelistPath),
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
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);
        $connection = new ContractFakeConnection(2);
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

    public function testPendingDownlinksFlushAfterDeviceLogin(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $queue = new ContractFakePendingDownlinkQueue();
        $hub = new DeviceHubServer(
            new Whitelist($this->whitelistPath),
            $mqtt,
            downlinkQueue: $queue
        );
        $connection = new ContractFakeConnection(6);
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

    public function testAuthenticatedMeasurementPublishesDecodedEventWithoutDebugFields(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);
        $connection = new ContractFakeConnection(3);
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
        self::assertSame('heart_rate', $mqtt->telemetry[0][1]['type']);
        self::assertSame(2, $mqtt->telemetry[0][1]['schemaVersion']);
        self::assertSame(['bpm' => 72], $mqtt->telemetry[0][1]['data']);
        self::assertSame('wonlex-json', $mqtt->telemetry[0][1]['source']['protocol']);
        self::assertSame('upHeartRate', $mqtt->telemetry[0][1]['source']['nativeType']);
        self::assertArrayNotHasKey('debug', $mqtt->telemetry[0][1]);

        self::assertSame('downlink', $mqtt->raw[2][1]['direction']);
        $ack = $adapter->decodeIncoming(base64_decode($mqtt->raw[2][1]['debug']['payload'], true));
        self::assertIsArray($ack);
        self::assertSame('upHeartRate', $ack['type']);
        self::assertSame(274611, $ack['ident']);
        self::assertSame('s:reply', $ack['ref']);
        self::assertSame('868705080300697', $ack['imei']);
    }

    public function testOnlineDownlinkPublishesCommandMetadata(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);
        $connection = new ContractFakeConnection(4);
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

    public function testVivistarOnlineDownlinkPublishesCommandMetadata(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);
        $connection = new ContractFakeConnection(5);

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

    public function testFourPTouchTakePillsOnlineDownlinkPublishesArmConvertedAudioMetadata(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);
        $connection = new ContractFakeConnection(8);
        $adapter = new \Hub\Protocol\Adapter\FourPTouchAdapter();

        $hub->onOpen($connection);
        $hub->onMessage($connection, '[3G*7597567372*000D*LK,50,100,100]');

        $payload = \Hub\Command\DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                'time' => '11:25',
                'enabled' => true,
                'frequency' => 3,
                'custom' => '1010',
            ],
            'number' => 3,
            'reminderText' => 'meds',
            'voiceData' => 'data:audio/wav;base64,' . $this->sampleWavBase64(),
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
        self::assertSame('text', $mqtt->raw[1][1]['debug']['encoding'] ?? null);
        self::assertStringStartsWith('[3G*7597567372*', $mqtt->raw[1][1]['debug']['payload'] ?? '');
        self::assertStringContainsString('TAKEPILLS,11:25-1-3-1010,3,006D006500640073,', $mqtt->raw[1][1]['debug']['payload'] ?? '');
        self::assertStringStartsWith('IyFBTVIK', $payload['payload']['fields'][3] ?? '');
    }

    public function testVivistarTelemetryPacketsReceiveProtocolAck(): void
    {
        $mqtt = new ContractRecordingHubMqttBridge();
        $hub = new DeviceHubServer(new Whitelist($this->whitelistPath), $mqtt);
        $connection = new ContractFakeConnection(7);

        $hub->onOpen($connection);
        $hub->onMessage($connection, 'IWAP00865028000000308#');
        $hub->onMessage($connection, 'IWAP49,72#');

        self::assertCount(2, $connection->sent);
        self::assertStringStartsWith('IWBP00,', $connection->sent[0]);
        self::assertStringEndsWith('#', $connection->sent[0]);
        self::assertSame('IWBP49#', $connection->sent[1]);
    }

    private function sampleWavBase64(): string
    {
        $sampleRate = 8000;
        $channels = 1;
        $bitsPerSample = 16;
        $data = str_repeat(pack('v', 0), 800);

        $byteRate = (int)($sampleRate * $channels * ($bitsPerSample / 8));
        $blockAlign = (int)($channels * ($bitsPerSample / 8));
        $header = 'RIFF'
            . pack('V', 36 + strlen($data))
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)
            . pack('v', 1)
            . pack('v', $channels)
            . pack('V', $sampleRate)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bitsPerSample)
            . 'data'
            . pack('V', strlen($data));

        return base64_encode($header . $data);
    }
}

final class ContractRecordingHubMqttBridge extends HubMqttBridge
{
    public array $raw = [];
    public array $statuses = [];
    public array $events = [];
    public array $telemetry = [];

    public function __construct()
    {
    }

    public function publishRaw(string $imei, array $payload, string $deviceType = 'watch', string $licenseId = '0', string $company = 'null'): void
    {
        $this->raw[] = [$imei, $payload];
    }

    public function publishStatus(string $imei, array $payload, bool $retain = true, string $deviceType = 'watch', string $licenseId = '0', string $company = 'null'): void
    {
        $this->statuses[] = [$imei, $payload, $retain];
    }

    public function publishEvent(string $imei, array $payload, string $deviceType = 'watch', string $licenseId = '0', string $company = 'null'): void
    {
        $this->events[] = [$imei, $payload];
    }

    public function publishTelemetry(string $imei, array $payload, string $deviceType = 'watch', string $licenseId = '0', string $company = 'null'): void
    {
        $this->telemetry[] = [$imei, $payload];
    }
}

final class ContractFakeConnection implements ConnectionInterface
{
    public int $resourceId;
    public array $sent = [];
    public bool $closed = false;

    public function __construct(int $resourceId)
    {
        $this->resourceId = $resourceId;
    }

    public function send($data): static
    {
        $this->sent[] = $data;
        return $this;
    }

    public function close(): static
    {
        $this->closed = true;
        return $this;
    }
}

final class ContractFakePendingDownlinkQueue implements PendingDownlinkQueue
{
    /** @var array<string, array<string, PendingDownlink>> */
    private array $items = [];
    public bool $failEnqueue = false;
    public int $lastTtl = 0;

    public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink
    {
        if ($this->failEnqueue) {
            throw new \RuntimeException('Redis unavailable');
        }

        $this->lastTtl = $ttlSeconds;
        $dedupeKey = $this->dedupeKey($bytes, $command);
        $downlink = new PendingDownlink($imei, $dedupeKey, $bytes, $command, time(), time() + $ttlSeconds);
        $this->items[$imei][$dedupeKey] = $downlink;

        return $downlink;
    }

    public function pendingFor(string $imei): array
    {
        return array_values($this->items[$imei] ?? []);
    }

    public function remove(PendingDownlink $downlink): void
    {
        unset($this->items[$downlink->imei][$downlink->dedupeKey]);
    }

    private function dedupeKey(string $bytes, ?array $command): string
    {
        $nativeType = is_array($command) ? (string)($command['nativeType'] ?? '') : '';
        if ($nativeType !== '') {
            return 'command:' . (string)($command['protocol'] ?? 'unknown') . ':' . $nativeType;
        }

        return 'raw:' . hash('sha256', $bytes);
    }
}
