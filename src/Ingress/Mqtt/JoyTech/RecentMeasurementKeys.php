<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use Predis\ClientInterface;

/**
 * As chaves das últimas medições publicadas de cada termómetro. O aparelho manda as mesmas 30 a
 * cada ligação, e o que já está aqui já saiu.
 */
final class RecentMeasurementKeys
{
    private const KEEP = 60;

    /** @var array<string, list<string>> sem Redis, só enquanto o processo vive */
    private array $local = [];

    public function __construct(
        private readonly ?ClientInterface $redis = null,
        private readonly string $prefix = 'hub:joytech:thermometer-seen',
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
            $this->local[$imei] = array_slice([$key, ...$this->local[$imei] ?? []], 0, self::KEEP);
            return;
        }

        $this->redis->lpush($this->prefix . ':' . $imei, [$key]);
        $this->redis->ltrim($this->prefix . ':' . $imei, 0, self::KEEP - 1);
    }
}
