<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\HubMqttBridge;
use Hub\Device\MessageFanout;
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

    /**
     * A derivação para os streams acontece no mesmo sítio onde a mensagem é publicada, e
     * leva o payload já serializado -- o mesmo que vai para o fio, sem segunda codificação.
     */
    public function testPublishingFansOutUnderTheScopeOfTheDeviceThatSentIt(): void
    {
        $fanout = new MessageFanout();
        $bridge = new HubMqttBridge(new FakeMqttPublisher(), 'havicare-hub', null, $fanout);

        $received = [];
        $fanout->subscribe(
            'hitcare/1001/telemetry',
            static function (string $topic, string $json) use (&$received): void {
                $received[] = [$topic, $json];
            }
        );

        $bridge->publishTelemetry('861265061009822', ['type' => 'heart_rate'], 'watch', 1001, 'hitcare');

        self::assertCount(1, $received);
        self::assertSame('havicare-hub/hitcare/1001/watch/861265061009822/telemetry', $received[0][0]);
        self::assertSame('{"type":"heart_rate"}', $received[0][1]);
    }

    /**
     * A licença 1001 do hitcare e a 1001 do havicare são clientes diferentes, e por isso a
     * chave de encaminhamento é o par empresa+licença. Se fosse só o número, este ouvinte
     * recebia dados de outro cliente.
     */
    public function testTheSameLicenceNumberInAnotherCompanyIsADifferentScope(): void
    {
        $fanout = new MessageFanout();
        $bridge = new HubMqttBridge(new FakeMqttPublisher(), 'havicare-hub', null, $fanout);

        $delivered = 0;
        $fanout->subscribe('hitcare/1001/telemetry', static function () use (&$delivered): void {
            $delivered++;
        });

        $bridge->publishTelemetry('861265061009833', ['type' => 'heart_rate'], 'watch', 1001, 'otherCare');

        self::assertSame(0, $delivered);
    }

    /** Cada canal é a sua própria chave: quem só quer eventos não paga a telemetria. */
    public function testEachChannelIsItsOwnKey(): void
    {
        $fanout = new MessageFanout();
        $bridge = new HubMqttBridge(new FakeMqttPublisher(), 'havicare-hub', null, $fanout);

        $events = 0;
        $fanout->subscribe('hitcare/1001/events', static function () use (&$events): void {
            $events++;
        });

        $bridge->publishTelemetry('861265061009822', ['type' => 'heart_rate'], 'watch', 1001, 'hitcare');
        self::assertSame(0, $events);

        $bridge->publishEvent('861265061009822', ['type' => 'sos'], 'watch', 1001, 'hitcare');
        self::assertSame(1, $events);
    }

    /** Um dispositivo sem dono publica sob `null/0`, que é um âmbito que nenhum token produz. */
    public function testAnOwnerlessDeviceLandsOnAScopeNoTenantCanHold(): void
    {
        $fanout = new MessageFanout();
        $bridge = new HubMqttBridge(new FakeMqttPublisher(), 'havicare-hub', null, $fanout);

        $seen = [];
        $fanout->subscribe('null/0/raw', static function (string $topic) use (&$seen): void {
            $seen[] = $topic;
        });

        $bridge->publishRaw('861265061009844', ['direction' => 'uplink']);

        self::assertSame(['havicare-hub/null/0/watch/861265061009844/raw'], $seen);
    }

    /** Sem ninguém à escuta, publicar não custa mais do que uma procura falhada. */
    public function testPublishingWithNoListenersIsHarmless(): void
    {
        $fanout = new MessageFanout();
        $bridge = new HubMqttBridge(new FakeMqttPublisher(), 'havicare-hub', null, $fanout);

        $bridge->publishTelemetry('861265061009822', ['type' => 'heart_rate'], 'watch', 1001, 'hitcare');

        self::assertSame(0, $fanout->listenerCount());
    }

    /** O `unsubscribe` devolvido tem de largar o ouvinte, ou uma ligação fechada continua a receber. */
    public function testUnsubscribingReleasesTheListener(): void
    {
        $fanout = new MessageFanout();
        $bridge = new HubMqttBridge(new FakeMqttPublisher(), 'havicare-hub', null, $fanout);

        $delivered = 0;
        $unsubscribe = $fanout->subscribe('hitcare/1001/telemetry', static function () use (&$delivered): void {
            $delivered++;
        });

        $bridge->publishTelemetry('861265061009822', ['type' => 'heart_rate'], 'watch', 1001, 'hitcare');
        self::assertSame(1, $delivered);
        self::assertSame(1, $fanout->listenerCount());

        $unsubscribe();
        self::assertSame(0, $fanout->listenerCount());

        $bridge->publishTelemetry('861265061009822', ['type' => 'heart_rate'], 'watch', 1001, 'hitcare');
        self::assertSame(1, $delivered, 'depois de largar o ouvinte não pode chegar mais nada');
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
