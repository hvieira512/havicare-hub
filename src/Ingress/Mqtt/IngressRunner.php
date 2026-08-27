<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

use Hub\Log\Logger;
use React\EventLoop\LoopInterface;

/** Arranca os ingresses MQTT registados e conduz os seus loops. */
final class IngressRunner
{
    /** @var array<string, MqttIngress> */
    private array $ingresses = [];

    public function __construct(private readonly LoopInterface $loop)
    {
    }

    /**
     * Um ingress nulo é ignorado, para um fornecedor desligado não precisar de uma condição
     * em quem o registou.
     */
    public function add(string $name, ?MqttIngress $ingress): void
    {
        if ($ingress !== null) {
            $this->ingresses[$name] = $ingress;
        }
    }

    /**
     * @throws \RuntimeException quando um ingress falha a subscrição; quem chama é que decide
     *         se isso é fatal
     */
    public function start(): void
    {
        foreach ($this->ingresses as $name => $ingress) {
            try {
                $ingress->start();
            } catch (\Throwable $e) {
                throw new \RuntimeException("{$name} subscription failed: {$e->getMessage()}", 0, $e);
            }
        }
    }

    public function scheduleTicks(float $interval = 0.05, float $timeout = 0.001): void
    {
        foreach ($this->ingresses as $name => $ingress) {
            $this->loop->addPeriodicTimer($interval, static function () use ($name, $ingress, $timeout): void {
                try {
                    $ingress->tick($timeout);
                } catch (\Throwable $e) {
                    Logger::channel('hub')->error("{$name} loop failed: {$e->getMessage()}");
                }
            });
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->ingresses);
    }
}
