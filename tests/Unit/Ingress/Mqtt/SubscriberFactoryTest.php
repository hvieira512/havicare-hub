<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\Ingress\Mqtt\MqttIngress;
use Hub\Ingress\Mqtt\SubscriberFactory;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;

final class SubscriberFactoryTest extends TestCase
{
    private function factory(): SubscriberFactory
    {
        return new SubscriberFactory(
            new RecordingConnectionFactory(new BrokerSettings(
                'broker.internal',
                1883,
                '',
                '',
                'hub',
                keepalive: 30,
                connectTimeout: 5,
                socketTimeout: 5,
            )),
        );
    }

    public function testReturnsTheIngressBuiltByTheCallback(): void
    {
        $ingress = new RecordingIngress();

        $bound = $this->factory()->bind('ncs-sub', '/voerka/#', static fn (): MqttIngress => $ingress);

        self::assertSame($ingress, $bound);
    }

    public function testSubscriberIsCreatedWithTheTopicSubscriptionAndConnected(): void
    {
        $connections = new RecordingConnectionFactory(new BrokerSettings(
            'broker.internal',
            1883,
            '',
            '',
            'hub',
            keepalive: 30,
            connectTimeout: 5,
            socketTimeout: 5,
        ));

        (new SubscriberFactory($connections))->bind(
            'ncs-sub',
            '/voerka/#',
            static fn (MqttClient $client): MqttIngress => new RecordingIngress(),
        );

        self::assertCount(1, $connections->created);
        self::assertSame('hub-ncs-sub', $connections->created[0]->getClientId());

        $subscriptions = $connections->repositories[0]->getSubscriptionsMatchingTopic('/voerka/anything');
        self::assertCount(1, $subscriptions);
        self::assertSame('/voerka/#', $subscriptions[0]->getTopicFilter());
        self::assertSame(MqttClient::QOS_AT_LEAST_ONCE, $subscriptions[0]->getQualityOfServiceLevel());

        // A ligação inicial tem de retomar a sessão do lado do broker.
        self::assertSame([false], $connections->cleanSessions);
    }

    public function testDispatchesReceivedMessagesToTheIngressBuiltAfterwards(): void
    {
        $connections = new RecordingConnectionFactory(new BrokerSettings(
            'broker.internal',
            1883,
            '',
            '',
            'hub',
            keepalive: 30,
            connectTimeout: 5,
            socketTimeout: 5,
        ));

        $ingress = new RecordingIngress();
        (new SubscriberFactory($connections))->bind(
            'ncs-sub',
            '/voerka/#',
            static fn (): MqttIngress => $ingress,
        );

        // A closure da subscrição é registada antes de o ingress existir, e isto afirma que a
        // referência para trás ficou resolvida.
        $subscription = $connections->repositories[0]->getSubscriptionsMatchingTopic('/voerka/x')[0];
        $subscription->getCallback()('/voerka/x', 'payload');

        self::assertSame([['/voerka/x', 'payload']], $ingress->received);
    }

    /**
     * O id de um subscritor não pode levar pid: com `cleanSession = false`, cada reinício
     * abria uma sessão nova e deixava a anterior órfã a segurar a subscrição.
     *
     * O id é truncado a 23 caracteres, e por isso dois pids diferentes chegaram a truncar
     * para o mesmo id e a expulsar-se um ao outro.
     */
    public function testSubscriberClientIdsNeverCarryThePid(): void
    {
        $connections = new RecordingConnectionFactory(new BrokerSettings(
            'radar.internal',
            1883,
            '',
            '',
            'qinglanst-radar',
            keepalive: 60,
            connectTimeout: 5,
            socketTimeout: 5,
        ));

        (new SubscriberFactory($connections))->bind(
            'sub',
            'radar/1001/#',
            static fn (): MqttIngress => new RecordingIngress(),
        );

        self::assertSame('qinglanst-radar-sub', $connections->created[0]->getClientId());
        self::assertSame([false], $connections->cleanSessions);
    }

    /** A reconexão tem de reutilizar o mesmo id, ou o recuo trocava a sessão a cada tentativa. */
    public function testReconnectingKeepsTheSameClientId(): void
    {
        $connections = new RecordingConnectionFactory(new BrokerSettings(
            'radar.internal',
            1883,
            '',
            '',
            'qinglanst-radar',
            keepalive: 60,
            connectTimeout: 5,
            socketTimeout: 5,
        ));

        $reconnect = null;
        (new SubscriberFactory($connections))->bind(
            'sub',
            'radar/1001/#',
            static function (MqttClient $client, callable $makeClient) use (&$reconnect): MqttIngress {
                $reconnect = $makeClient;
                return new RecordingIngress();
            },
        );

        $reconnect();

        self::assertCount(2, $connections->created);
        self::assertSame(
            $connections->created[0]->getClientId(),
            $connections->created[1]->getClientId(),
        );
    }
}

final class RecordingConnectionFactory extends ConnectionFactory
{
    /** @var list<MqttClient> */
    public array $created = [];
    /** @var list<Repository> */
    public array $repositories = [];
    /** @var list<bool> */
    public array $cleanSessions = [];

    public function create(string $suffix, bool $stableClientId = false, ?Repository $repository = null): MqttClient
    {
        $client = parent::create($suffix, $stableClientId, $repository);
        $this->created[] = $client;
        if ($repository !== null) {
            $this->repositories[] = $repository;
        }

        return $client;
    }

    public function connect(MqttClient $client, bool $cleanSession = true): MqttClient
    {
        $this->cleanSessions[] = $cleanSession;

        return $client;
    }
}

final class RecordingIngress implements MqttIngress
{
    /** @var list<array{0: string, 1: string}> */
    public array $received = [];

    public function start(): void
    {
    }

    public function tick(float $timeout = 0.01): void
    {
    }

    public function handleReceivedMessage(string $topic, string $payload): void
    {
        $this->received[] = [$topic, $payload];
    }
}
