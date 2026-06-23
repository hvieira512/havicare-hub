<?php

declare(strict_types=1);

namespace Tests\Unit\Ncs;

use Hub\HubMqttBridge;
use Hub\Ncs\NcsMessageNormalizer;
use Hub\Ncs\NcsMqttIngressBridge;
use Hub\Ncs\NcsTopic;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;

final class NcsMqttIngressBridgeTest extends TestCase
{
    private string $whitelistPath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-ncs-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, json_encode([
            'ncs-gateway-01' => [
                'supplier' => 'Voerka',
                'model' => 'W812',
                'deviceType' => 'ncs',
                'licenseId' => '1001',
                'sourceSystem' => 'voerka',
                'sourceDeviceId' => 'gw-001',
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (is_file($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
    }

    public function testBridgePublishesNormalizedMessagesToNcsTopics(): void
    {
        $publisher = new NcsFakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher, 'prefix');
        $subscriber = new NcsFakeMqttSubscriber();
        $ingress = new NcsMqttIngressBridge($subscriber, new Whitelist($this->whitelistPath), $bridge);

        $ingress->handleReceivedMessage('/voerka/ncs/devices/gw-001/events', json_encode([
            'from' => 'gw-001',
            'type' => 6,
            'timestamp' => 1718700000,
            'payload' => [
                'id' => 'button-07',
                'key' => '8',
                'transparent' => ['raw' => '0A01'],
                'location' => [
                    'lat' => 41.1579,
                    'lon' => -8.6291,
                    'accuracy' => 12,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([
            'prefix/1001/ncs/ncs-gateway-01/raw',
            'prefix/1001/ncs/ncs-gateway-01/events',
            'prefix/1001/ncs/ncs-gateway-01/telemetry',
        ], array_column($publisher->published, 'topic'));
        self::assertSame(MqttClient::QOS_AT_LEAST_ONCE, $publisher->published[1]['qos']);
        self::assertFalse($publisher->published[1]['retain']);

        $raw = json_decode($publisher->published[0]['message'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('voerka-ncs', $raw['debug']['protocol']);
        self::assertSame('/voerka/ncs/devices/gw-001/events', $raw['debug']['sourceTopic']);

        $event = json_decode($publisher->published[1]['message'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ncs.event', $event['type']);
        self::assertSame('6', (string)$event['data']['code']);
        self::assertSame('8', (string)$event['data']['key']);
        self::assertSame('button-07', $event['data']['deviceId']);
        self::assertSame('help_call', $event['data']['event']);
        self::assertTrue($event['data']['alarm']);
        self::assertSame(['raw' => '0A01'], $event['data']['transparent']);

        $telemetry = json_decode($publisher->published[2]['message'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('location', $telemetry['type']);
        self::assertSame(41.1579, $telemetry['data']['lat']);
        self::assertSame(-8.6291, $telemetry['data']['lon']);
        self::assertSame('gps', $telemetry['data']['source']);
    }

    public function testBridgePublishesRetainedStatusForOnlineState(): void
    {
        $publisher = new NcsFakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher);
        $ingress = new NcsMqttIngressBridge(new NcsFakeMqttSubscriber(), new Whitelist($this->whitelistPath), $bridge);

        $ingress->handleReceivedMessage('/voerka/default/devices/gw-001/status/online', json_encode([
            'from' => 'gw-001',
            'payload' => ['status' => ['online' => false]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([
            '1001/ncs/ncs-gateway-01/raw',
            '1001/ncs/ncs-gateway-01/status',
            '1001/ncs/ncs-gateway-01/events',
        ], array_column($publisher->published, 'topic'));
        self::assertTrue($publisher->published[1]['retain']);

        $status = json_decode($publisher->published[1]['message'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('offline', $status['state']);
        self::assertFalse($status['data']['online']);
    }

    public function testBridgeRejectsMalformedAndUnregisteredMessages(): void
    {
        $publisher = new NcsFakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher);
        $ingress = new NcsMqttIngressBridge(new NcsFakeMqttSubscriber(), new Whitelist($this->whitelistPath), $bridge);

        $ingress->handleReceivedMessage('/voerka/ncs/devices/gw-001/events', '{bad json');
        $ingress->handleReceivedMessage('/voerka/ncs/devices/gw-001/events', json_encode(['type' => 6], JSON_THROW_ON_ERROR));
        $ingress->handleReceivedMessage('/voerka/ncs/devices/other/events', json_encode([
            'from' => 'gw-001',
            'type' => 6,
            'payload' => [],
        ], JSON_THROW_ON_ERROR));
        $ingress->handleReceivedMessage('/voerka/ncs/devices/unknown/events', json_encode([
            'from' => 'unknown',
            'type' => 6,
            'payload' => [],
        ], JSON_THROW_ON_ERROR));
        $ingress->handleReceivedMessage('/voerka/ncs/devices/gw-001/attrs', json_encode([
            'from' => 'gw-001',
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $publisher->published);
    }

    public function testTopicParserRecognizesObservedFamilies(): void
    {
        $topic = NcsTopic::parse('/voerka/1001/devices/gw-001/status/online');
        self::assertNotNull($topic);
        self::assertSame('1001', $topic->scope);
        self::assertSame('gw-001', $topic->sourceId);
        self::assertSame('status.online', $topic->nativeType());

        self::assertNull(NcsTopic::parse('/voerka/ncs/gateways/gw-001/events'));
    }

    public function testNormalizerIncludesTransparentAndLocationPayloads(): void
    {
        $normalizer = new NcsMessageNormalizer();
        $normalized = $normalizer->normalize(
            new NcsTopic('/voerka/ncs/devices/gw-001/events', 'ncs', 'gw-001', 'events'),
            [
                'from' => 'gw-001',
                'type' => 6,
                'payload' => [
                    'key' => '8',
                    'transparent' => ['raw' => 'A1'],
                    'location' => ['lat' => 40.0, 'lon' => -7.0],
                ],
            ],
            [
                'imei' => 'ncs-gateway-01',
                'supplier' => 'Voerka',
                'model' => 'W812',
                'deviceType' => 'ncs',
                'licenseId' => '1001',
                'simNumber' => '',
                'deviceId' => '',
                'sourceSystem' => 'voerka',
                'sourceDeviceId' => 'gw-001',
            ]
        );

        self::assertSame('help_call', $normalized['event']['data']['event']);
        self::assertTrue($normalized['event']['data']['alarm']);
        self::assertSame(['raw' => 'A1'], $normalized['event']['data']['transparent']);
        self::assertSame(40.0, $normalized['event']['data']['location']['lat']);
        self::assertSame(-7.0, $normalized['telemetry']['data']['lon']);
    }
}

final class NcsFakeMqttPublisher extends MqttClient
{
    /** @var array<int, array{topic: string, message: string, qos: int, retain: bool}> */
    public array $published = [];

    public function __construct()
    {
    }

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        $this->published[] = [
            'topic' => $topic,
            'message' => $message,
            'qos' => $qualityOfService,
            'retain' => $retain,
        ];
    }
}

final class NcsFakeMqttSubscriber extends MqttClient
{
    public function __construct()
    {
    }

    public function subscribe(string $topicFilter, ?callable $callback = null, int $qualityOfService = self::QOS_AT_MOST_ONCE): void
    {
    }

    public function loopOnce(float $availableTime, bool $allowSleep = false, int $sleepMicroseconds = 100000): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function disconnect(): void
    {
    }
}
