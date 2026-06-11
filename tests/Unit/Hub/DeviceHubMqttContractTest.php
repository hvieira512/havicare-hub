<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use App\Hub\DeviceHubServer;
use App\Hub\HubMqttBridge;
use App\Protocol\Adapter\WonlexAdapter;
use App\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

final class DeviceHubMqttContractTest extends TestCase
{
    private string $whitelistPath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-contract-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, json_encode([
            '865028000000308' => ['supplier' => 'Vivistar', 'model' => 'VIVISTAR-CARE'],
            '868705080300697' => ['supplier' => 'Wonlex', 'model' => 'HW20PRO'],
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
            'data' => ['heartRate' => 72],
        ]));

        self::assertCount(2, $mqtt->raw);
        self::assertCount(2, $mqtt->events);
        self::assertSame('device.connected', $mqtt->events[0][1]['type']);
        self::assertSame('device.telemetry.heart_rate', $mqtt->events[1][1]['type']);
        self::assertSame(2, $mqtt->events[1][1]['schemaVersion']);
        self::assertSame(['bpm' => 72], $mqtt->events[1][1]['data']);
        self::assertSame('wonlex-json', $mqtt->events[1][1]['source']['protocol']);
        self::assertSame('upHeartRate', $mqtt->events[1][1]['source']['nativeType']);
        self::assertArrayNotHasKey('debug', $mqtt->events[1][1]);
    }
}

final class ContractRecordingHubMqttBridge extends HubMqttBridge
{
    public array $raw = [];
    public array $statuses = [];
    public array $events = [];

    public function __construct()
    {
    }

    public function publishRaw(string $imei, array $payload): void
    {
        $this->raw[] = [$imei, $payload];
    }

    public function publishStatus(string $imei, array $payload, bool $retain = true): void
    {
        $this->statuses[] = [$imei, $payload, $retain];
    }

    public function publishEvent(string $imei, array $payload): void
    {
        $this->events[] = [$imei, $payload];
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

    public function send($data)
    {
        $this->sent[] = $data;
        return $this;
    }

    public function close()
    {
        $this->closed = true;
        return $this;
    }
}
