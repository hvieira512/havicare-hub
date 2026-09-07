<?php

namespace Hub\Api\Repository;

use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

/**
 * As identidades bloqueadas de propósito. Chaveada por `identity` -- o que o aparelho anuncia
 * (IMEI, MAC ou uid) --, a mesma string que o caminho de rejeição recebe.
 */
final class DenylistRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function add(string $identity, string $protocol = '', ?string $note = null, string $createdBy = ''): void
    {
        // Bloquear de novo o mesmo aparelho refresca quem/porquê, mas guarda o `created_at`
        // do primeiro bloqueio.
        $stmt = $this->pdo->prepare('
            INSERT INTO denylist (identity, protocol, note, created_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                protocol = VALUES(protocol),
                note = VALUES(note),
                created_by = VALUES(created_by)
        ');
        $stmt->execute([$identity, $protocol, $note, $createdBy]);
    }

    public function remove(string $identity): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM denylist WHERE identity = ?');
        $stmt->execute([$identity]);

        return $stmt->rowCount() > 0;
    }

    public function exists(string $identity): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM denylist WHERE identity = ? LIMIT 1');
        $stmt->execute([$identity]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return TimestampFormatter::normalizeRows($this->pdo
            ->query('SELECT identity, protocol, note, created_by, created_at FROM denylist ORDER BY created_at DESC, identity')
            ->fetchAll());
    }
}
