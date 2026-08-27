<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

use Hub\Mqtt\ConnectionFactory;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use PhpMqtt\Client\Subscription;

/**
 * Liga um ingress MQTT subscritor ao seu broker.
 *
 * A subscrição tem de ser registada no repositório do cliente *antes* de o ingress existir,
 * porque o ingress precisa do cliente já ligado no construtor. Essa circularidade resolve-se
 * com um contentor por referência, que esta classe guarda num sítio só.
 *
 * Todos os subscritores retomam a sessão do lado do broker (`cleanSession = false`, fixo
 * abaixo), e por isso o id do cliente tem de ser estável -- sem pid. Um id com pid muda a
 * cada reinício do processo, o que faz o broker abrir uma sessão nova e deixar a anterior
 * órfã, a segurar a subscrição e a encher fila de QoS 1 para um cliente que nunca volta. Não
 * é uma opção: as duas coisas só estão certas juntas, e por isso não há flag para as separar.
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
