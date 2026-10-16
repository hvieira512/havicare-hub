<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use Predis\ClientInterface;

/**
 * A hora da medição mais recente que o hub já publicou, por tensiómetro e grupo de memória. Ler
 * a memória não a apaga, e o que não for mais recente do que isto já saiu.
 */
final class BloodPressureHistoryMarks
{
    /** @var array<string, array<int, int>> sem Redis, só enquanto o processo vive */
    private array $local = [];

    public function __construct(
        private readonly ?ClientInterface $redis = null,
        private readonly string $prefix = 'hub:joytech:history-mark',
    ) {
    }

    public function get(string $imei, int $group): int
    {
        if ($this->redis === null) {
            return $this->local[$imei][$group] ?? 0;
        }

        return (int)$this->redis->hget($this->prefix . ':' . $imei, (string)$group);
    }

    public function advance(string $imei, int $group, int $at): void
    {
        if ($at <= $this->get($imei, $group)) {
            return;
        }
        if ($this->redis === null) {
            $this->local[$imei][$group] = $at;
            return;
        }

        $this->redis->hset($this->prefix . ':' . $imei, (string)$group, (string)$at);
    }
}
