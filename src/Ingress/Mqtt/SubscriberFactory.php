<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

use Hub\Mqtt\ConnectionFactory;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use PhpMqtt\Client\Subscription;

/**
 * Wires a subscribing MQTT ingress to its broker.
 *
 * The subscription has to be registered on the client's repository *before* the
 * ingress exists, because the ingress needs the connected client in its
 * constructor. That circularity is resolved with a by-reference holder, which
 * this class keeps in one place instead of at every call site.
 *
 * Every subscriber here resumes its broker-side session (`cleanSession = false`,
 * fixed below), so the client id has to be stable -- sem pid. Um id com pid muda
 * a cada reinício do processo, o que faz o broker abrir uma sessão nova e deixar
 * a anterior órfã, a segurar a subscrição e a encher fila de QoS 1 para um
 * cliente que nunca volta. Não é uma opção: as duas coisas só estão certas
 * juntas, e por isso não há flag para as separar.
 */
final class SubscriberFactory
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    /**
     * @param callable(MqttClient, callable(): MqttClient): MqttIngress $make
     *        receives the connected subscriber and a reconnect factory, and
     *        returns the ingress that should handle the topic's messages
     */
    public function bind(string $clientSuffix, string $topicFilter, callable $make): MqttIngress
    {
        $ingress = null;

        $repository = static function () use ($topicFilter, &$ingress): MemoryRepository {
            $repository = new MemoryRepository();
            $repository->addSubscription(new Subscription(
                $topicFilter,
                MqttClient::QOS_AT_LEAST_ONCE,
                static function (string $topic, string $message) use (&$ingress): void {
                    $ingress?->handleReceivedMessage($topic, $message);
                }
            ));

            return $repository;
        };

        $subscriber = $this->connections->create($clientSuffix, true, $repository());
        $reconnect = function () use ($clientSuffix, $repository): MqttClient {
            return $this->connections->connect(
                $this->connections->create($clientSuffix, true, $repository()),
                false,
            );
        };

        $ingress = $make($subscriber, $reconnect);
        $this->connections->connect($subscriber, false);

        return $ingress;
    }
}
