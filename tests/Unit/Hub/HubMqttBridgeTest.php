<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\HubMqttBridge;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;

final class HubMqttBridgeTest extends TestCase
{
    public function testPublishRetriesOnceWithReconnectedPublisher(): void
    {
        $failedPublisher = new FakeMqttPublisher(shouldFail: true);
        $reconnectedPublisher = new FakeMqttPublisher();
        $bridge = new HubMqttBridge(
            $failedPublisher,
            'prefix',
            static fn (): MqttClient => $reconnectedPublisher
        );

        $bridge->publishRaw('8800000015', [
            'direction' => 'uplink',
        ]);

        self::assertSame(1, $failedPublisher->publishCalls);
        self::assertSame(1, $failedPublisher->disconnectCalls);
        self::assertSame(1, $reconnectedPublisher->publishCalls);
        self::assertSame('prefix/null/0/watch/8800000015/raw', $reconnectedPublisher->lastTopic);
    }

    public function testEventsPublishWithQosOne(): void
    {
        $publisher = new FakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher, 'prefix');

        $bridge->publishEvent('8800000015', [
            'type' => 'device.downlink.queued',
        ]);

        self::assertSame('prefix/null/0/watch/8800000015/events', $publisher->lastTopic);
        self::assertSame(MqttClient::QOS_AT_LEAST_ONCE, $publisher->lastQualityOfService);
    }

    public function testStatusPublishesWithQosOneAndStaysRetained(): void
    {
        $publisher = new FakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher, 'prefix');

        $bridge->publishStatus('8800000015', ['state' => 'offline']);

        self::assertSame('prefix/null/0/watch/8800000015/status', $publisher->lastTopic);
        self::assertSame(MqttClient::QOS_AT_LEAST_ONCE, $publisher->lastQualityOfService);
        self::assertTrue($publisher->lastRetain);
    }

    public function testAnErrorStatusKeepsQosOneWithoutBeingRetained(): void
    {
        $publisher = new FakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher, 'prefix');

        $bridge->publishStatus('8800000015', ['state' => 'error'], retain: false);

        self::assertSame(MqttClient::QOS_AT_LEAST_ONCE, $publisher->lastQualityOfService);
        self::assertFalse($publisher->lastRetain);
    }

    public function testTelemetryStaysAtQosZero(): void
    {
        $publisher = new FakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher, 'prefix');

        $bridge->publishTelemetry('8800000015', ['type' => 'heart_rate']);

        self::assertSame(MqttClient::QOS_AT_MOST_ONCE, $publisher->lastQualityOfService);
        self::assertFalse($publisher->lastRetain);
    }
}

final class FakeMqttPublisher extends MqttClient
{
    public int $publishCalls = 0;
    public int $disconnectCalls = 0;
    public ?string $lastTopic = null;
    public ?int $lastQualityOfService = null;
    public ?bool $lastRetain = null;

    public function __construct(private bool $shouldFail = false)
    {
    }

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        $this->publishCalls++;
        $this->lastTopic = $topic;
        $this->lastQualityOfService = $qualityOfService;
        $this->lastRetain = $retain;

        if ($this->shouldFail) {
            throw new \RuntimeException('socket closed');
        }
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function disconnect(): void
    {
        $this->disconnectCalls++;
    }
}
