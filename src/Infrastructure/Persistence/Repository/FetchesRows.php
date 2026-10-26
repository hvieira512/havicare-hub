<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

/**
 * Uma consulta preparada que devolve linhas com o `created_at` e o `updated_at` já em ISO; quem
 * o usa tem o `$pdo`.
 */
trait FetchesRows
{
    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    /**
     * Corre o trabalho numa transacção; dentro de outra já aberta, junta-se a ela, que o PDO não
     * aninha transacções e quem a abriu é que a fecha.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function transactional(\Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->pdo->commit();

        return $result;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return TimestampFormatter::normalizeRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
