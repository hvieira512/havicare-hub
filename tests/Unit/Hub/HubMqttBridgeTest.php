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

    public function testRawStaysAtQosZeroAndIsNotRetained(): void
    {
        $publisher = new FakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher, 'prefix');

        $bridge->publishRaw('8800000015', ['direction' => 'uplink']);

        self::assertSame(MqttClient::QOS_AT_MOST_ONCE, $publisher->lastQualityOfService);
        self::assertFalse($publisher->lastRetain);
    }

    /**
     * Um dispositivo com dono, que é o caso normal. Os outros testes usam os sentinelas, e
     * com eles um erro na ordem dos dois primeiros segmentos não se via.
     */
    public function testATopicCarriesTheCompanyAndTheLicenceInThatOrder(): void
    {
        $publisher = new FakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher, 'havicare-hub');

        $bridge->publishTelemetry(
            '861265061009822',
            ['type' => 'heart_rate'],
            'watch',
            1001,
            'hitcare',
        );

        self::assertSame(
            'havicare-hub/hitcare/1001/watch/861265061009822/telemetry',
            $publisher->lastTopic,
        );
    }

    /** O tipo de dispositivo é o quarto segmento, e não é sempre `watch`. */
    public function testTheDeviceTypeIsItsOwnSegment(): void
    {
        $publisher = new FakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher, 'havicare-hub');

        $bridge->publishTelemetry('eec5000202f9', [], 'diaper_sensor', 1001, 'hitcare');

        self::assertSame(
            'havicare-hub/hitcare/1001/diaper_sensor/eec5000202f9/telemetry',
            $publisher->lastTopic,
        );
    }

    /** Sem prefixo o tópico tem cinco segmentos, e não começa por barra. */
    public function testAnEmptyPrefixLeavesFiveSegmentsWithoutALeadingSlash(): void
    {
        $publisher = new FakeMqttPublisher();
        $bridge = new HubMqttBridge($publisher, '');

        $bridge->publishTelemetry('861265061009822', [], 'watch', 1001, 'hitcare');

        self::assertSame('hitcare/1001/watch/861265061009822/telemetry', $publisher->lastTopic);
        self::assertCount(5, explode('/', (string)$publisher->lastTopic));
    }

    /**
     * O `licenseId` é o único sítio do hub onde o número da licença é texto, e a conversão
     * acontece aqui -- em memória, na base de dados e na API é sempre inteiro.
     */
    public function testTheLicenceIsTheOnlyPlaceAnIntegerBecomesText(): void
    {
        $bridge = new HubMqttBridge(new FakeMqttPublisher(), '');

        self::assertSame(
            'hitcare/0/watch/861265061009822/telemetry',
            $bridge->deviceTopic('hitcare', 0, 'watch', '861265061009822', 'telemetry'),
        );
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
