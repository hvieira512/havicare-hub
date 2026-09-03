<?php

namespace Hub\Api\Repository;

use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class ApiUserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return TimestampFormatter::normalizeRows($this->pdo
            ->query($this->selectSql() . ' ORDER BY u.username')
            ->fetchAll());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->selectSql(true) . ' WHERE u.id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare($this->selectSql(true) . ' WHERE lower(u.username) = lower(?)');
        $stmt->execute([$username]);

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    /**
     * A licença entra só pelo `licenseRefId`.
     *
     * Havia aqui um `licenseId` a par, gravado numa coluna própria. Era o mesmo número que a
     * linha de `licenses` apontada já continha, e o `resolveLicense()` do serviço derivava os
     * dois da mesma linha -- pelo que nunca podiam divergir por este caminho, e um deles era
     * sempre supérfluo. Fica a referência, que é a que desambigua duas empresas com o mesmo
     * número de licença.
     */
    public function create(string $username, string $passwordHash, string $role, bool $enabled, ?int $licenseRefId = null): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO api_users (username, password_hash, role, license_ref_id, enabled)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$username, $passwordHash, $role, $licenseRefId, $enabled ? 1 : 0]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $username, string $role, bool $enabled, ?string $passwordHash = null, ?int $licenseRefId = null): bool
    {
        if ($passwordHash !== null) {
            $stmt = $this->pdo->prepare('
                UPDATE api_users
                SET username = ?, password_hash = ?, role = ?, license_ref_id = ?, enabled = ?
                WHERE id = ?
            ');
            $stmt->execute([$username, $passwordHash, $role, $licenseRefId, $enabled ? 1 : 0, $id]);
        } else {
            $stmt = $this->pdo->prepare('
                UPDATE api_users
                SET username = ?, role = ?, license_ref_id = ?, enabled = ?
                WHERE id = ?
            ');
            $stmt->execute([$username, $role, $licenseRefId, $enabled ? 1 : 0, $id]);
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

    private function selectSql(bool $includePasswordHash = false): string
    {
        $password = $includePasswordHash ? ', u.password_hash' : '';

        // O `license_id` sai da licença apontada, e não de uma coluna própria: é o mesmo
        // número, por um caminho onde não pode desencontrar-se da referência.
        return "
            SELECT u.id, u.username{$password}, u.role, l.license_id, u.license_ref_id,
                   u.enabled, u.created_at, u.updated_at,
                   l.company_id, c.name AS company_name, l.name AS license_name
            FROM api_users u
            LEFT JOIN licenses l ON l.id = u.license_ref_id
            LEFT JOIN companies c ON c.id = l.company_id
        ";
    }
}
