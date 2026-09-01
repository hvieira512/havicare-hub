<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

use Hub\Mqtt\ConnectionFactory;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use PhpMqtt\Client\Subscription;

/**
 * Liga um ingress MQTT subscritor ao seu broker. A subscrição tem de ser registada *antes* de
 * o ingress existir, porque ele precisa do cliente já ligado no construtor -- essa
 * circularidade resolve-se com um contentor por referência.
 *
 * Todos retomam a sessão do lado do broker (`cleanSession = false`), e por isso o id do
 * cliente tem de ser estável, sem pid: um id que muda a cada reinício deixa a sessão anterior
 * órfã a segurar a subscrição. As duas coisas só estão certas juntas, e não há flag para as
 * separar.
 */
final class SubscriberFactory
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    /**
     * @param callable(MqttClient, callable(): MqttClient): MqttIngress $make
     *        recebe o subscritor ligado e uma fábrica de reconexão, e devolve o ingress que
     *        deve tratar as mensagens do tópico
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
