<?php

declare(strict_types=1);

namespace Hub\Support;

/** Um valor por chave que vale `$ttlSeconds`; o TTL é a latência com que uma alteração se vê. */
final class TtlCache
{
    /** O processo corre meses: cheio o mapa, sai a chave carregada há mais tempo. */
    public const MAX_KEYS = 10000;

    /** @var array<string, array{value: mixed, loadedAt: int}> */
    private array $entries = [];

    public function __construct(private int $ttlSeconds)
    {
        $this->ttlSeconds = max(0, $this->ttlSeconds);
    }

    /**
     * @template T
     * @param \Closure(): T $load
     * @return T
     */
    public function remember(string $key, \Closure $load): mixed
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry !== null && time() - $entry['loadedAt'] <= $this->ttlSeconds) {
            return $entry['value'];
        }

        $value = $load();
        if ($entry === null && count($this->entries) >= self::MAX_KEYS) {
            unset($this->entries[array_key_first($this->entries)]);
        }
        $this->entries[$key] = ['value' => $value, 'loadedAt' => time()];

        return $value;
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }
}
