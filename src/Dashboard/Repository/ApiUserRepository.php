<?php

namespace Hub\Dashboard\Repository;

use Hub\Dashboard\TimestampFormatter;
use PDO;

final class ApiUserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return TimestampFormatter::normalizeRows($this->pdo
            ->query('SELECT id, username, role, license_id, enabled, created_at, updated_at FROM api_users ORDER BY username')
            ->fetchAll());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash, role, license_id, enabled, created_at, updated_at FROM api_users WHERE id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash, role, license_id, enabled, created_at, updated_at FROM api_users WHERE lower(username) = lower(?)');
        $stmt->execute([$username]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    public function create(string $username, string $passwordHash, string $role, int $licenseId, bool $enabled): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO api_users (username, password_hash, role, license_id, enabled)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$username, $passwordHash, $role, $licenseId, $enabled ? 1 : 0]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $username, string $role, int $licenseId, bool $enabled, ?string $passwordHash = null): bool
    {
        if ($passwordHash !== null) {
            $stmt = $this->pdo->prepare('
                UPDATE api_users
                SET username = ?, password_hash = ?, role = ?, license_id = ?, enabled = ?
                WHERE id = ?
            ');
            $stmt->execute([$username, $passwordHash, $role, $licenseId, $enabled ? 1 : 0, $id]);
        } else {
            $stmt = $this->pdo->prepare('
                UPDATE api_users
                SET username = ?, role = ?, license_id = ?, enabled = ?
                WHERE id = ?
            ');
            $stmt->execute([$username, $role, $licenseId, $enabled ? 1 : 0, $id]);
        }

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM api_users WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function usernameExistsForDifferentId(int $id, string $username): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM api_users WHERE id != ? AND lower(username) = lower(?)');
        $stmt->execute([$id, $username]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
