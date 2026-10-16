<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use Predis\ClientInterface;

/**
 * O que o hub já publicou de cada tensiómetro, por grupo de memória: a hora do registo mais
 * recente lido da memória, e os resultados ao vivo que vieram depois. Ler a memória não a apaga.
 */
final class BloodPressureHistoryMarks
{
    /** @var array<string, int> sem Redis, só enquanto o processo vive */
    private array $localMarks = [];

    /** @var array<string, array<string, int>> */
    private array $localLive = [];

    public function __construct(
        private readonly ?ClientInterface $redis = null,
        private readonly string $prefix = 'hub:joytech',
    ) {
    }

    public function mark(string $imei, int $group): int
    {
        if ($this->redis === null) {
            return $this->localMarks[$imei . ':' . $group] ?? 0;
        }

        return (int)$this->redis->hget($this->prefix . ':history-mark:' . $imei, (string)$group);
    }

    /** Os ao vivo até à marca já não servem: a memória abaixo dela não sai. */
    public function advance(string $imei, int $group, int $at): void
    {
        if ($at <= $this->mark($imei, $group)) {
            return;
        }
        if ($this->redis === null) {
            $this->localMarks[$imei . ':' . $group] = $at;
            $this->localLive[$imei . ':' . $group] = array_filter(
                $this->localLive[$imei . ':' . $group] ?? [],
                static fn (int $liveAt): bool => $liveAt > $at,
            );
            return;
        }

        $this->redis->hset($this->prefix . ':history-mark:' . $imei, (string)$group, (string)$at);
        $this->redis->zremrangebyscore($this->liveKey($imei, $group), '-inf', (string)$at);
    }

    /** @param string $key o resultado tal como veio, que a memória repete igual */
    public function rememberLive(string $imei, int $group, string $key, int $at): void
    {
        if ($this->redis === null) {
            $this->localLive[$imei . ':' . $group][$key] = $at;
            return;
        }

        $this->redis->zadd($this->liveKey($imei, $group), [$key => $at]);
    }

    public function wasLive(string $imei, int $group, string $key): bool
    {
        if ($this->redis === null) {
            return isset($this->localLive[$imei . ':' . $group][$key]);
        }

        return $this->redis->zscore($this->liveKey($imei, $group), $key) !== null;
    }

    private function liveKey(string $imei, int $group): string
    {
        return $this->prefix . ':live:' . $imei . ':' . $group;
    }
}
