<?php

namespace Hub\Location;

use Predis\ClientInterface;

final class RedisLocationResolutionCache implements LocationResolutionCacheContract
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $prefix = 'hub:location:resolution',
    ) {
    }

    public function get(string $evidenceKey): ?array
    {
        $raw = $this->redis->get($this->key($evidenceKey));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !in_array($entry['status'] ?? null, ['resolved', 'unresolved'], true)) {
            $this->redis->del([$this->key($evidenceKey)]);
            return null;
        }
        if (($entry['status'] ?? null) === 'resolved' && !is_array($entry['coordinates'] ?? null)) {
            $this->redis->del([$this->key($evidenceKey)]);
            return null;
        }

        return $entry;
    }

    public function putResolved(string $evidenceKey, array $coordinates, int $ttlSeconds): void
    {
        $this->put($evidenceKey, ['status' => 'resolved', 'coordinates' => $coordinates], $ttlSeconds);
    }

    public function putUnresolved(string $evidenceKey, int $ttlSeconds): void
    {
        $this->put($evidenceKey, ['status' => 'unresolved'], $ttlSeconds);
    }

    private function put(string $evidenceKey, array $entry, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        $this->redis->setex(
            $this->key($evidenceKey),
            $ttlSeconds,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function key(string $evidenceKey): string
    {
        return trim($this->prefix, ':') . ':' . $evidenceKey;
    }
}
