<?php

namespace Hub\Registry;

use Hub\Api\Repository\DenylistRepository;

/**
 * As identidades bloqueadas, em memória, consultadas no caminho de rejeição para calar um
 * aparelho estranho na fonte -- sem notificação, sem evento.
 *
 * O conjunto recarrega da base de dados a cada janela de TTL, como a `Whitelist`: um bloqueio
 * feito pela API torna-se visível ao caminho da ingestão dentro de segundos. Como é o mesmo
 * processo, a escrita própria (`block()`/`unblock()`) muda o conjunto de imediato.
 */
final class Denylist
{
    /** @var array<string, true> */
    private array $blocked = [];

    private int $loadedAt = 0;

    public function __construct(
        private ?DenylistRepository $db = null,
        private int $ttlSeconds = 5,
    ) {
        $this->reload();
    }

    public function contains(string $identity): bool
    {
        if ($identity === '') {
            return false;
        }
        $this->refreshIfStale();

        return isset($this->blocked[$identity]);
    }

    public function block(string $identity, string $protocol = '', ?string $note = null, string $by = ''): void
    {
        $this->blocked[$identity] = true;
        $this->db?->add($identity, $protocol, $note, $by);
    }

    public function unblock(string $identity): void
    {
        unset($this->blocked[$identity]);
        $this->db?->remove($identity);
    }

    private function refreshIfStale(): void
    {
        if ($this->db !== null && (time() - $this->loadedAt) >= $this->ttlSeconds) {
            $this->reload();
        }
    }

    private function reload(): void
    {
        if ($this->db === null) {
            return;
        }

        $blocked = [];
        foreach ($this->db->all() as $row) {
            $identity = (string)($row['identity'] ?? '');
            if ($identity !== '') {
                $blocked[$identity] = true;
            }
        }
        $this->blocked = $blocked;
        $this->loadedAt = time();
    }
}
