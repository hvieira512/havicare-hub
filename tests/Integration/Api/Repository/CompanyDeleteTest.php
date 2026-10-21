<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Repository;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Infrastructure\Persistence\Repository\CompanyRepository;
use PDO;
use PDOException;
use PDOStatement;
use Tests\Support\MysqlDashboardTestCase;

/** Apagar uma empresa leva as licenças com ela, ou não leva nada. */
final class CompanyDeleteTest extends MysqlDashboardTestCase
{
    public function testAFailedDeleteKeepsTheLicences(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $db = ApiDataAccess::fromDatabase($database);
        $companyId = $db->companies->create('acme');
        $db->licenses->create($companyId, 4242, 'Acme');

        try {
            (new CompanyRepository($this->failingOnCompanyDelete($pdo)))->delete($companyId);
            self::fail('o DELETE da empresa devia ter rebentado');
        } catch (PDOException) {
            // O que se prende é o estado que a falha deixa.
        }

        self::assertFalse($pdo->inTransaction(), 'a transacção ficou aberta depois da falha');
        self::assertNotNull($db->licenses->findByCompanyAndLicense($companyId, 4242));
    }

    private function failingOnCompanyDelete(PDO $inner): PDO
    {
        return new class ($inner) extends PDO {
            public function __construct(private PDO $inner)
            {
            }

            /** @param array<int, mixed> $options */
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (str_contains($query, 'DELETE FROM companies')) {
                    throw new PDOException('SQLSTATE[HY000]: Lock wait timeout exceeded');
                }

                return $this->inner->prepare($query, $options);
            }

            public function beginTransaction(): bool
            {
                return $this->inner->beginTransaction();
            }

            public function commit(): bool
            {
                return $this->inner->commit();
            }

            public function rollBack(): bool
            {
                return $this->inner->rollBack();
            }

            public function inTransaction(): bool
            {
                return $this->inner->inTransaction();
            }
        };
    }
}
