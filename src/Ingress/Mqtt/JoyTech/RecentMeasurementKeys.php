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
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $prefix = 'hub:joytech:thermometer-seen',
        private readonly int $keep = 60,
    ) {
    }

    /** @return list<string> */
    public function all(string $imei): array
    {
        return array_map('strval', $this->redis->lrange($this->prefix . ':' . $imei, 0, -1));
    }

    public function add(string $imei, string $key): void
    {
        $this->redis->lpush($this->prefix . ':' . $imei, [$key]);
        $this->redis->ltrim($this->prefix . ':' . $imei, 0, $this->keep - 1);
    }
}
