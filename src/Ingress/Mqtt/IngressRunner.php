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

    /**
     * A chave curta de cada ingestão, indexada pelo nome legível: o nome vai para os logs, a
     * chave é o fornecedor com que o `StartupBanner` a procura.
     *
     * @var array<string, string>
     */
    private array $keys = [];

    public function __construct(private readonly LoopInterface $loop)
    {
    }

    /** Um ingress nulo, de um fornecedor desligado, é ignorado. */
    public function add(string $name, ?MqttIngress $ingress, string $key = ''): void
    {
        if ($ingress === null) {
            return;
        }

        $this->ingresses[$name] = $ingress;
        $this->keys[$name] = $key !== '' ? $key : $name;
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

    /**
     * Põe cada ingestão a girar, e as que têm fila a entregá-la. Dois temporizadores: o tique
     * do loop MQTT quer-se apertado, e para a entrega um segundo chega.
     */
    public function scheduleTicks(float $interval = 0.05, float $timeout = 0.001, float $dispatchInterval = 1.0): void
    {
        foreach ($this->ingresses as $name => $ingress) {
            $this->loop->addPeriodicTimer($interval, static function () use ($name, $ingress, $timeout): void {
                try {
                    $ingress->tick($timeout);
                } catch (\Throwable $e) {
                    Logger::channel('hub')->error("{$name} loop failed: {$e->getMessage()}");
                }
            });

            if (!$ingress instanceof DispatchesQueued) {
                continue;
            }

            // Engolido como o tique: uma entrega falhada fica em fila para a próxima ronda.
            $this->loop->addPeriodicTimer($dispatchInterval, static function () use ($name, $ingress): void {
                try {
                    $ingress->dispatchQueued();
                } catch (\Throwable $e) {
                    Logger::channel('hub')->error("{$name} queued dispatch failed: {$e->getMessage()}");
                }
            });
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->ingresses);
    }

    /**
     * Pela ordem em que foram registadas.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values($this->keys);
    }
}
