<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\QinglanstBridge;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O radar não abre sessão: ligado é ter falado, desligado é três minutos calado. */
final class RadarConnectionTest extends TestCase
{
    private const RADAR = '594B3CB31A87';
    private const PREFIX = 'test:dashboard:radar';

    private InMemoryRedisClient $redis;
    private DeviceStore $store;
    private RecordingHubMqttBridge $mqtt;
    private QinglanstBridge $bridge;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedisClient();
        $this->store = new DeviceStore($this->redis, prefix: self::PREFIX);
        $this->mqtt = new RecordingHubMqttBridge();
        $this->bridge = new QinglanstBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([self::RADAR => IngressFixtures::radar()]),
            $this->mqtt,
            deviceStore: $this->store,
            idleTimeoutSeconds: 180,
        );
    }

    public function testARadarThatStartsTalkingIsAnnouncedConnectedOnce(): void
    {
        $this->bridge->handleReceivedMessage('radar/1001/' . self::RADAR, $this->position());
        $this->bridge->handleReceivedMessage('radar/1001/' . self::RADAR, $this->position());

        self::assertSame(['device.connected'], $this->connectionEvents());
        self::assertSame(['online'], array_column(array_column($this->mqtt->statuses, 'payload'), 'state'));
        self::assertSame(['device.connected'], array_column($this->store->recent(self::RADAR, 'connections'), 'type'));
    }

    public function testARadarSilentForThreeMinutesIsAnnouncedDisconnected(): void
    {
        $this->bridge->handleReceivedMessage('radar/1001/' . self::RADAR, $this->position());
        $this->redis->zadd(self::PREFIX . ':online-devices-by-last-seen', [self::RADAR => time() - 181]);

        $this->bridge->expireIdleRadars();
        $this->bridge->expireIdleRadars();

        self::assertSame(['device.connected', 'device.disconnected'], $this->connectionEvents());
        self::assertSame('offline', $this->mqtt->statuses[1]['payload']['state'] ?? null);
        self::assertFalse($this->store->device(self::RADAR)['online']);
    }

    public function testARadarThatStillTalksIsNotExpired(): void
    {
        $this->bridge->handleReceivedMessage('radar/1001/' . self::RADAR, $this->position());
        $this->redis->zadd(self::PREFIX . ':online-devices-by-last-seen', [self::RADAR => time() - 170]);

        $this->bridge->expireIdleRadars();

        self::assertSame(['device.connected'], $this->connectionEvents());
    }

    /** @return list<string> */
    private function connectionEvents(): array
    {
        return array_values(array_filter(
            array_map(static fn(array $event): string => (string)($event['payload']['type'] ?? ''), $this->mqtt->events),
            static fn(string $type): bool => str_starts_with($type, 'device.'),
        ));
    }

    private function position(): string
    {
        $bytes = [0x01, 0x0A, 0x0B, 0x0C, 0, 0, 0, 0, 0, 0, 0, 0, 0x04, 0x01, 0x00, 0x09];

        return (string)json_encode([
            'payload' => [
                'deviceCode' => self::RADAR,
                'position' => base64_encode(implode('', array_map('chr', $bytes))),
            ],
        ]);
    }
}
