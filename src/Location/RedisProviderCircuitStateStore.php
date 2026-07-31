<?php

namespace Hub\Location;

use Predis\ClientInterface;

final class RedisProviderCircuitStateStore implements ProviderCircuitStateStoreContract
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $prefix = 'hub:location:circuit',
    ) {
    }

    public function get(string $provider): array
    {
        $raw = $this->redis->get($this->key($provider));
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            return ['consecutiveFailures' => 0, 'openUntil' => 0];
        }

        return [
            'consecutiveFailures' => max(0, (int)($state['consecutiveFailures'] ?? 0)),
            'openUntil' => max(0, (int)($state['openUntil'] ?? 0)),
        ];
    }

    public function put(string $provider, array $state, int $ttlSeconds): void
    {
        $this->redis->setex(
            $this->key($provider),
            max(1, $ttlSeconds),
            json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public function clear(string $provider): void
    {
        $this->redis->del([$this->key($provider)]);
    }

    private function key(string $provider): string
    {
        $safeProvider = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($provider)) ?: 'unknown';
        return trim($this->prefix, ':') . ':' . $safeProvider;
    }
}
