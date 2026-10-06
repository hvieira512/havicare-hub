<?php

declare(strict_types=1);

namespace Hub\Registry;

use Hub\Infrastructure\Persistence\Repository\DenylistRepository;

/**
 * As identidades bloqueadas, consultadas no caminho de rejeição para calar um aparelho na
 * fonte. Recarregam da base a cada TTL, como a `Whitelist`; `block()`/`unblock()` mudam-nas já.
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
