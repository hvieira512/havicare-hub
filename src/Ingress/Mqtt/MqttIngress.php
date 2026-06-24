<?php

namespace Hub\Ingress\Mqtt;

interface MqttIngress
{
    public function start(): void;
    public function tick(float $timeout = 0.01): void;
    public function handleReceivedMessage(string $topic, string $payload): void;
}
