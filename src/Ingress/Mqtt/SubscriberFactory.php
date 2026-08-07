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
 */
final class SubscriberFactory
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly bool $stableClientId = true,
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

        $subscriber = $this->connections->create($clientSuffix, $this->stableClientId, $repository());
        $reconnect = function () use ($clientSuffix, $repository): MqttClient {
            return $this->connections->connect(
                $this->connections->create($clientSuffix, $this->stableClientId, $repository()),
                false,
            );
        };

        $ingress = $make($subscriber, $reconnect);
        $this->connections->connect($subscriber, false);

        return $ingress;
    }
}
