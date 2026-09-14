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
     * A chave curta de cada ingestão, indexada pelo nome legível.
     *
     * Duas designações porque servem coisas diferentes: o nome vai nas mensagens de erro e nos
     * logs, a chave é a identidade do fornecedor com que o `StartupBanner` a procura. Sem a
     * chave, o arranque mantinha uma segunda lista à mão em paralelo com esta.
     *
     * @var array<string, string>
     */
    private array $keys = [];

    public function __construct(private readonly LoopInterface $loop)
    {
    }

    /**
     * Um ingress nulo é ignorado, para um fornecedor desligado não precisar de uma condição
     * em quem o registou.
     */
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
     * Põe cada ingestão a girar, e as que têm fila a entregá-la.
     *
     * Os dois temporizadores são separados por terem ritmos diferentes: o tique conduz o loop
     * MQTT e quer-se apertado, a entrega vai ao Redis e ao gateway e um segundo chega. Uma
     * ingestão sem fila não ganha o segundo temporizador.
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

            // Engolido como o tique: uma entrega falhada é uma ordem que fica em fila para a
            // próxima ronda, e não uma razão para o processo inteiro parar.
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
     * As chaves curtas das ingestões que arrancaram, pela ordem em que foram registadas.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values($this->keys);
    }
}
