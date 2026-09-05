<?php

namespace Hub\Location;

final class ArrayLocationResolutionCache implements LocationResolutionCacheContract
{
    // Teto de entradas do nível em memória: sem ele, cada ambiente de rádio distinto
    // deixa uma chave que só sai ao ser reconsultada, e o processo nunca reinicia.
    private const MAX_ENTRIES = 5000;

    /** @var array<string, array{expiresAt: float, entry: array<string, mixed>}> */
    private array $entries = [];

    public function get(string $evidenceKey): ?array
    {
        $cached = $this->entries[$evidenceKey] ?? null;
        if ($cached === null || $cached['expiresAt'] < microtime(true)) {
            unset($this->entries[$evidenceKey]);
            return null;
        }

        return $cached['entry'];
    }

    public function putResolved(string $evidenceKey, array $coordinates, int $ttlSeconds): void
    {
        $this->store($evidenceKey, ['status' => 'resolved', 'coordinates' => $coordinates], $ttlSeconds);
    }

    public function putUnresolved(string $evidenceKey, int $ttlSeconds): void
    {
        $this->store($evidenceKey, ['status' => 'unresolved'], $ttlSeconds);
    }

    /** @param array<string, mixed> $entry */
    private function store(string $evidenceKey, array $entry, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        if (!isset($this->entries[$evidenceKey])) {
            $this->evictToCapacity();
        }
        $this->entries[$evidenceKey] = [
            'expiresAt' => microtime(true) + $ttlSeconds,
            'entry' => $entry,
        ];
    }

    private function evictToCapacity(): void
    {
        if (count($this->entries) < self::MAX_ENTRIES) {
            return;
        }

        // Primeiro os expirados, que já não valem nada; só se ainda estiver cheio
        // é que se larga o mais antigo (a inserção mantém a ordem no array).
        $now = microtime(true);
        foreach ($this->entries as $key => $cached) {
            if ($cached['expiresAt'] < $now) {
                unset($this->entries[$key]);
            }
        }
        while (count($this->entries) >= self::MAX_ENTRIES) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }
}
