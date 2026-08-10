<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use PhpMqtt\Client\MqttClient;

/**
 * An MqttClient that never touches a socket.
 *
 * Ingress bridges subscribe in their constructor or start(), so tests need a
 * client that accepts the call and does nothing.
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
