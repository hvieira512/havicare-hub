<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Lefu;

use Hub\Device\PendingDownlinkQueue;
use Hub\Ingress\Mqtt\Lefu\LefuTorreBridge;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\OneShotDownlinkQueue;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Do gateway ao MQTT, com as tramas da pesagem real em que a FC foi medida. */
final class LefuTorreBridgeTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const SCALE = 'cfe908067329';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    private float $now = 1000.0;

    public function testTheSessionPutsTheScaleOnlineWithItsBatteryAndSetsTheClock(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true, 'battery' => 46, 'mtu' => 61]));

        self::assertSame('online', $mqtt->statuses[0]['payload']['state']);
        self::assertSame(['percent' => 46], self::telemetry($mqtt, 'battery')[0]['data']);
        self::assertSame('calibrateClock', $mqtt->gatewayCommands[0]['payload']['operation']);
        self::assertStringStartsWith('0406', $mqtt->gatewayCommands[0]['payload']['payload']['writes'][0]['hex']);
    }

    public function testACompleteWeighingPublishesWeightHeartRateAndImpedanceOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach (['010d6500942500000032a00f64330e', '010d6500942500000032a00f64330e', '010da500942500 4a0032a00f64330e'] as $hex) {
            $bridge->handleReceivedMessage(self::TOPIC, self::frame('fff3', $hex));
        }
        $bridge->handleReceivedMessage(self::TOPIC, self::frame('fff3', '020a180210536f00ff16db00'));
        $bridge->handleReceivedMessage(self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));
        $this->now += 0.3;
        $bridge->handleReceivedMessage(self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        self::assertSame([['weightKg' => 96.2]], array_column(self::telemetry($mqtt, 'weight'), 'data'));
        self::assertSame([['bpm' => 74]], array_column(self::telemetry($mqtt, 'heart_rate'), 'data'));
        self::assertSame([[
            'impedance50KhzOhm' => 400.0,
            'impedance100KhzOhm' => 363.5,
            'electrodes' => 'four_dual_frequency',
            'encodedImpedance50Khz' => 7295760,
            'encodedImpedance100Khz' => 14358271,
        ]], array_column(self::telemetry($mqtt, 'body_impedance'), 'data'));
        // Três estados diferentes; a trama intermédia repetida não sai.
        self::assertSame(['measuring', 'measured'], array_values(array_unique(array_column(array_column(self::telemetry($mqtt, 'weighing_status'), 'data'), 'heartRate'))));
        self::assertCount(2, self::telemetry($mqtt, 'weighing_status'));
    }

    /** Mau contacto: a impedância em claro vem implausível e não sai como leitura. */
    public function testAFailedImpedanceIsReportedAndNotPublishedAsAReading(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::frame('fff3', '010d3580fd25000000320000640000'));

        self::assertSame([], self::telemetry($mqtt, 'body_impedance'));
        self::assertCount(1, self::telemetry($mqtt, 'body_impedance_failure'));
        self::assertSame([['weightKg' => 97.25]], array_column(self::telemetry($mqtt, 'weight'), 'data'));
    }

    public function testTheScaleAnswerClosesTheCommandAndAReadIsReportedConfiguration(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $replies = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('markCommandReply')->willReturnCallback(
            static function (string $imei, string $type, mixed $ident, string $ref, ?bool $accepted) use (&$replies): void {
                $replies[] = [$type, $accepted];
            }
        );
        $bridge = $this->bridge($mqtt, null, $store);

        $bridge->handleReceivedMessage(self::TOPIC, self::frame('fff1', '06020101'));
        $bridge->handleReceivedMessage(self::TOPIC, self::frame('fff1', '07020200'));

        self::assertSame([['torre_06_set', false], ['torre_07_read', true]], $replies);
        self::assertSame(['settings' => ['impedance_measurement' => ['enabled' => true]]], self::telemetry($mqtt, 'device_config')[0]['data']);
    }

    /** O hub monta as escritas; o gateway só as faz, e só com sessão aberta. */
    public function testAQueuedSettingLeavesAsWritesWhenTheScaleConnects(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new OneShotDownlinkQueue('heartRateMeasurement', ['id' => 'op-1', 'command' => 'heartRateMeasurement', 'payload' => ['enabled' => true]]);

        $this->bridge($mqtt, $queue)->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true, 'mtu' => 61]));

        $command = $mqtt->gatewayCommands[1]['payload'];
        self::assertSame('heartRateMeasurement', $command['operation']);
        self::assertSame('op-1', $command['commandId']);
        self::assertSame([['char' => 'fff1', 'hex' => '06020100']], $command['payload']['writes']);
    }

    public function testAScaleOfAnotherSourceOrNotLinkedIsIgnored(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt, links: false)->handleReceivedMessage(self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        self::assertSame([], $mqtt->telemetry);
    }

    /** @return list<array<string, mixed>> */
    private static function telemetry(RecordingHubMqttBridge $mqtt, string $type): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['payload'],
            array_filter($mqtt->telemetry, static fn (array $entry): bool => ($entry['payload']['type'] ?? null) === $type),
        ));
    }

    private static function frame(string $characteristic, string $hex): string
    {
        return self::message('frame', ['char' => $characteristic, 'hex' => $hex]);
    }

    /** @param array<string, mixed> $payload */
    private static function message(string $kind, array $payload): string
    {
        return json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'lefu-torre-node',
            'kind' => $kind,
            'device' => ['mac' => self::SCALE, 'model' => 'CF632', 'firmware' => '005.015'],
            'payload' => $payload,
            'tzOffsetMinutes' => 60,
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(
        RecordingHubMqttBridge $mqtt,
        ?PendingDownlinkQueue $queue = null,
        ?DeviceStoreContract $store = null,
        bool $links = true,
    ): LefuTorreBridge {
        return new LefuTorreBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                self::SCALE => IngressFixtures::device('Unique Scales', 'HC-CF632BLE', 'scale'),
            ]),
            $mqtt,
            IngressFixtures::links($links),
            $queue,
            'havicare-hub/null/0/gw/+/raw',
            null,
            $store,
            fn (): float => $this->now,
        );
    }
}
