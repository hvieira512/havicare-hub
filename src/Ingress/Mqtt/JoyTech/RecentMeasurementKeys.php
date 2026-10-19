<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use Predis\ClientInterface;

/**
 * As chaves das últimas medições publicadas de cada aparelho que repete a memória a cada leitura,
 * como o termómetro e a balança: o que já está aqui já saiu.
 */
final class RecentMeasurementKeys
{
    /** @var array<string, list<string>> sem Redis, só enquanto o processo vive */
    private array $local = [];

    public function __construct(
        private readonly ?ClientInterface $redis = null,
        private readonly string $prefix = 'hub:joytech:thermometer-seen',
        private readonly int $keep = 60,
    ) {
    }

    /** @return list<string> */
    public function all(string $imei): array
    {
        if ($this->redis === null) {
            return $this->local[$imei] ?? [];
        }

        return array_map('strval', $this->redis->lrange($this->prefix . ':' . $imei, 0, -1));
    }

    public function add(string $imei, string $key): void
    {
        if ($this->redis === null) {
            $this->local[$imei] = array_slice([$key, ...$this->local[$imei] ?? []], 0, $this->keep);
            return;
        }

        $this->redis->lpush($this->prefix . ':' . $imei, [$key]);
        $this->redis->ltrim($this->prefix . ':' . $imei, 0, $this->keep - 1);
    }
}
