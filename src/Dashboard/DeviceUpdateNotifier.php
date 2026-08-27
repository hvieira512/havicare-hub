<?php

declare(strict_types=1);

namespace Hub\Dashboard;

/**
 * Diz aos streams abertos que o histórico de um dispositivo mudou.
 *
 * The ingest and the dashboard HTTP server share one process and one
 * DashboardStore, so a write can be announced directly instead of every stream
 * re-reading Redis on a timer to discover it.
 *
 * Aos ouvintes só se diz *qual* o dispositivo que mudou, nunca o quê: o stream continua a
 * ler o estado autoritativo por si, e por isso uma notificação perdida ou duplicada custa
 * uma leitura a mais e não um payload errado.
 */
class DeviceUpdateNotifier
{
    /** @var array<string, array<int, callable(): void>> */
    private array $listeners = [];

    private int $nextId = 1;

    /**
     * @param callable(): void $listener
     * @return callable(): void unsubscribe
     */
    public function subscribe(string $deviceKey, callable $listener): callable
    {
        $key = $this->normalize($deviceKey);
        $id = $this->nextId++;
        $this->listeners[$key][$id] = $listener;

        return function () use ($key, $id): void {
            unset($this->listeners[$key][$id]);
            if (($this->listeners[$key] ?? []) === []) {
                unset($this->listeners[$key]);
            }
        };
    }

    public function notify(string $deviceKey): void
    {
        foreach ($this->listeners[$this->normalize($deviceKey)] ?? [] as $listener) {
            $listener();
        }
    }

    /**
     * Para varreduras que tocam dispositivos que não nomeiam, como expirar comandos em todo
     * o store.
     */
    public function notifyAll(): void
    {
        foreach ($this->listeners as $listeners) {
            foreach ($listeners as $listener) {
                $listener();
            }
        }
    }

    public function listenerCount(): int
    {
        return array_sum(array_map('count', $this->listeners));
    }

    private function normalize(string $deviceKey): string
    {
        return strtolower(trim($deviceKey));
    }
}
