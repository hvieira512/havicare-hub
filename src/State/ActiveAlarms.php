<?php

declare(strict_types=1);

namespace Hub\State;

use Predis\ClientInterface;

/**
 * Os alarmes que um aparelho repete enquanto duram e que o hub já anunciou, num hash por
 * aparelho: sobrevivem a um reinício, e cada campo leva o seu prazo no próprio valor.
 */
final class ActiveAlarms
{
    /** O hash inteiro dura o prazo mais longo que algum campo pede. */
    private const KEY_TTL_SECONDS = 86400;

    /** @var array<string, array<string, string>> sem Redis, na memória do processo */
    private array $memory = [];

    public function __construct(private readonly ?ClientInterface $redis = null)
    {
    }

    public function get(string $imei, string $field): ?string
    {
        $key = RedisKeys::activeAlarms($imei);
        $stored = $this->redis !== null ? $this->redis->hget($key, $field) : ($this->memory[$key][$field] ?? null);
        [$until, $value] = explode(':', (string)$stored, 2) + [1 => null];

        return $value !== null && (int)$until > time() ? $value : null;
    }

    /** Escrever o mesmo valor renova o prazo; `null` apaga. */
    public function set(string $imei, string $field, ?string $value, int $ttlSeconds): void
    {
        $key = RedisKeys::activeAlarms($imei);
        if ($value === null) {
            $this->redis?->hdel($key, [$field]);
            unset($this->memory[$key][$field]);
            return;
        }

        $stored = (time() + $ttlSeconds) . ':' . $value;
        if ($this->redis === null) {
            $this->memory[$key][$field] = $stored;
            return;
        }
        $this->redis->hset($key, $field, $stored);
        $this->redis->expire($key, self::KEY_TTL_SECONDS);
    }
}
