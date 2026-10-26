<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use PDO;

final class CompanyRepository
{
    use FetchesRows;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->fetchRows("SELECT c.id, c.name, c.created_at, c.updated_at, (SELECT COUNT(*) FROM licenses WHERE company_id = c.id) AS license_count FROM companies c ORDER BY c.name");
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->fetchOne('SELECT * FROM companies WHERE name = ?', [$name]);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM companies WHERE id = ?', [$id]);
    }

    public function create(string $name): int
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return (int)($existing['id'] ?? 0);
        }

        $stmt = $this->pdo->prepare('INSERT INTO companies (name) VALUES (?)');
        $stmt->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE companies SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $this->pdo->prepare('DELETE FROM licenses WHERE company_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$id]);
        });
    }
}
