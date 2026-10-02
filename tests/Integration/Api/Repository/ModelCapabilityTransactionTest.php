<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Repository;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Repository\ModelCapabilityRepository;
use PDO;
use PDOException;
use PDOStatement;
use Tests\Support\MysqlDashboardTestCase;

/**
 * O hub é um processo de vida longa com a ligação sempre aberta: uma transacção que fique por
 * fechar prende os locks e faz a escrita seguinte rebentar na mesma ligação.
 */
final class ModelCapabilityTransactionTest extends MysqlDashboardTestCase
{
    public function testAFailedInsertLeavesNoOpenTransaction(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $model = ApiDataAccess::fromDatabase($database)->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);

        $repository = new ModelCapabilityRepository($this->failingOnInsert($pdo));

        try {
            $repository->replaceForModelId((int)$model['id'], ['heart_rate', 'location']);
            self::fail('o INSERT devia ter rebentado');
        } catch (PDOException) {
            // A falha é o ponto de partida; o que se prende é o estado que ela deixa.
        }

        self::assertFalse($pdo->inTransaction(), 'a transacção ficou aberta depois da falha');
        $pdo->beginTransaction();
        $pdo->rollBack();
    }

    /** O mesmo PDO, com o `INSERT` a rebentar como rebenta uma coluna estreita ou um lock. */
    private function failingOnInsert(PDO $inner): PDO
    {
        return new class ($inner) extends PDO {
            public function __construct(private PDO $inner)
            {
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (str_contains($query, 'INSERT INTO model_capabilities')) {
                    throw new PDOException('SQLSTATE[22001]: String data, right truncated');
                }

                return $this->inner->prepare($query, $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetch): PDOStatement|false
            {
                return $this->inner->query($query, $fetchMode, ...$fetch);
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
