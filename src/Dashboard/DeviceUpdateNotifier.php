<?php

declare(strict_types=1);

namespace Hub\Dashboard;

/**
 * Tells open device streams that a device's history has changed.
 *
 * The ingest and the dashboard HTTP server share one process and one
 * DashboardStore, so a write can be announced directly instead of every stream
 * re-reading Redis on a timer to discover it.
 *
 * Listeners are only told *which* device changed, never what: the stream still
 * reads the authoritative state itself, so a missed or duplicated notification
 * costs a redundant read rather than a wrong payload.
 */
final class DeviceUpdateNotifier
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
     * For sweeps that touch devices they do not name, such as expiring commands
     * across the whole store.
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
