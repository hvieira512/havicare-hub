<?php

declare(strict_types=1);

namespace Hub\Support;

/** «No máximo uma vez por janela», por chave, em segundos do relógio de quem chama. */
final class Throttle
{
    /** O processo corre meses: cheio o mapa, sai a chave deixada passar há mais tempo. */
    public const MAX_KEYS = 10000;

    /** @var array<string, float> */
    private array $allowedAt = [];

    public function allow(string $key, float $seconds, float $now): bool
    {
        if ($now - ($this->allowedAt[$key] ?? -INF) < $seconds) {
            return false;
        }

        // Reinserida no fim, a primeira chave do mapa é sempre a mais antiga.
        unset($this->allowedAt[$key]);
        if (count($this->allowedAt) >= self::MAX_KEYS) {
            unset($this->allowedAt[array_key_first($this->allowedAt)]);
        }
        $this->allowedAt[$key] = $now;

        return true;
    }
}
