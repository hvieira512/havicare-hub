<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use PhpMqtt\Client\MqttClient;

/**
 * Um `MqttClient` que nunca toca num socket.
 *
 * Os bridges de ingress subscrevem no construtor ou no `start()`, e por isso os testes
 * precisam de um cliente que aceite a chamada e não faça nada.
 */
final class FakeMqttSubscriber extends MqttClient
{
    /** @var list<array{topicFilter: string, qualityOfService: int}> */
    public array $subscriptions = [];

    public function __construct(string $clientId = 'fake-subscriber')
    {
        parent::__construct('127.0.0.1', 1883, $clientId);
    }

    public function subscribe(string $topicFilter, ?callable $callback = null, int $qualityOfService = 0): void
    {
        $this->subscriptions[] = [
            'topicFilter' => $topicFilter,
            'qualityOfService' => $qualityOfService,
        ];
    }
}
