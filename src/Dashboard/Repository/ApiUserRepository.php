<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class ApiUserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo
            ->query('SELECT id, username, role, license_id, enabled, created_at, updated_at FROM api_users ORDER BY username')
            ->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash, role, license_id, enabled, created_at, updated_at FROM api_users WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash, role, license_id, enabled, created_at, updated_at FROM api_users WHERE lower(username) = lower(?)');
        $stmt->execute([$username]);

        return $stmt->fetch() ?: null;
    }

    public function create(string $username, string $passwordHash, string $role, int $licenseId, bool $enabled): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('
            INSERT INTO api_users (username, password_hash, role, license_id, enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$username, $passwordHash, $role, $licenseId, $enabled ? 1 : 0, $now, $now]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $username, string $role, int $licenseId, bool $enabled, ?string $passwordHash = null): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($passwordHash !== null) {
            $stmt = $this->pdo->prepare('
                UPDATE api_users
                SET username = ?, password_hash = ?, role = ?, license_id = ?, enabled = ?, updated_at = ?
                WHERE id = ?
            ');
            $stmt->execute([$username, $passwordHash, $role, $licenseId, $enabled ? 1 : 0, $now, $id]);
        } else {
            $stmt = $this->pdo->prepare('
                UPDATE api_users
                SET username = ?, role = ?, license_id = ?, enabled = ?, updated_at = ?
                WHERE id = ?
            ');
            $stmt->execute([$username, $role, $licenseId, $enabled ? 1 : 0, $now, $id]);
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
