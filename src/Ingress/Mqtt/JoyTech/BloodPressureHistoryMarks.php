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
    /**
     * Os ao vivo só saem da lista quando a marca os passa; numa memória que nunca se lê até ao
     * fim, sai-se por aqui. A marca fica: sem ela, a memória toda voltava a sair.
     */
    private const LIVE_TTL_SECONDS = 90 * 86400;

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $prefix = 'hub:joytech',
    ) {
    }

    public function mark(string $imei, int $group): int
    {
        return (int)$this->redis->hget($this->prefix . ':history-mark:' . $imei, (string)$group);
    }

    /** Os ao vivo até à marca já não servem: a memória abaixo dela não sai. */
    public function advance(string $imei, int $group, int $at): void
    {
        if ($at <= $this->mark($imei, $group)) {
            return;
        }
        $this->redis->hset($this->prefix . ':history-mark:' . $imei, (string)$group, (string)$at);
        $this->redis->zremrangebyscore($this->liveKey($imei, $group), '-inf', (string)$at);
    }

    /** @param string $key o resultado tal como veio, que a memória repete igual */
    public function rememberLive(string $imei, int $group, string $key, int $at): void
    {
        $this->redis->zadd($this->liveKey($imei, $group), [$key => $at]);
        $this->redis->expire($this->liveKey($imei, $group), self::LIVE_TTL_SECONDS);
    }

    public function wasLive(string $imei, int $group, string $key): bool
    {
        return $this->redis->zscore($this->liveKey($imei, $group), $key) !== null;
    }

    private function liveKey(string $imei, int $group): string
    {
        return $this->prefix . ':live:' . $imei . ':' . $group;
    }
}
