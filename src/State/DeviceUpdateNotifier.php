<?php

declare(strict_types=1);

namespace Hub\State;

/**
 * Diz aos streams abertos qual o dispositivo cujo histórico mudou, nunca o quê: o stream relê o
 * estado, e uma notificação perdida ou duplicada custa só uma leitura.
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
