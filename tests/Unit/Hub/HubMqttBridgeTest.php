<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use App\Hub\HubMqttBridge;
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
        self::assertSame('prefix/devices/8800000015/raw', $reconnectedPublisher->lastTopic);
    }
}

final class FakeMqttPublisher extends MqttClient
{
    public int $publishCalls = 0;
    public int $disconnectCalls = 0;
    public ?string $lastTopic = null;

    public function __construct(private bool $shouldFail = false)
    {
    }

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        $this->publishCalls++;
        $this->lastTopic = $topic;

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
