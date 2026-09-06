<?php

namespace Hub\Infrastructure\Persistence;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Um PDO que refaz a ligação quando o MySQL a larga — um `wait_timeout` de inactividade ou
 * um reinício do servidor. Sem isto, um único `new PDO` partilhado deixava o processo a atirar
 * «server has gone away» em todas as consultas até alguém o reiniciar à mão, incluindo a
 * recarga da whitelist que autoriza os aparelhos.
 *
 * É uma fachada com o tipo `PDO` — os repositórios continuam a receber um `PDO` — mas a ligação
 * real vive no `$inner` e pode ser refeita. Por isso o construtor **não** chama o pai.
 *
 * A repetição é só para operações idempotentes: o `prepare` e o `query` voltam a correr na
 * ligação nova (preparar e um `SELECT` não têm efeito colateral). O `exec` e as transacções
 * reconectam mas **não** repetem — repetir uma escrita que já tinha ido antes da queda
 * duplicava-a. Nesses casos a chamada actual falha e a seguinte já apanha a ligação fresca.
 */
final class ReconnectingPdo extends PDO
{
    private ?PDO $inner = null;
    private bool $inTransaction = false;

    /** @param \Closure(): PDO $connect Cria uma ligação nova, já configurada. */
    public function __construct(private \Closure $connect)
    {
        // Sem parent::__construct de propósito: ver o docblock da classe.
    }

    private function inner(): PDO
    {
        return $this->inner ??= ($this->connect)();
    }

    private function drop(): void
    {
        $this->inner = null;
        $this->inTransaction = false;
    }

    private function isRetryable(PDOException $e): bool
    {
        if ($this->inTransaction) {
            return false;
        }
        $code = (int)($e->errorInfo[1] ?? 0);
        return $code === 2006 || $code === 2013
            || str_contains($e->getMessage(), 'server has gone away')
            || str_contains($e->getMessage(), 'Lost connection');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            return $this->inner()->prepare($query, $options);
        } catch (PDOException $e) {
            if (!$this->isRetryable($e)) {
                throw $e;
            }
            $this->drop();
            return $this->inner()->prepare($query, $options);
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        try {
            return $this->inner()->query($query, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $e) {
            if (!$this->isRetryable($e)) {
                throw $e;
            }
            $this->drop();
            return $this->inner()->query($query, $fetchMode, ...$fetchModeArgs);
        }
    }

    public function exec(string $statement): int|false
    {
        try {
            return $this->inner()->exec($statement);
        } catch (PDOException $e) {
            // Pode ser uma escrita: reconecta para a próxima, mas não repete esta.
            if ($this->isRetryable($e)) {
                $this->drop();
            }
            throw $e;
        }
    }

    public function beginTransaction(): bool
    {
        $result = $this->inner()->beginTransaction();
        $this->inTransaction = true;
        return $result;
    }

    public function commit(): bool
    {
        try {
            return $this->inner()->commit();
        } finally {
            $this->inTransaction = false;
        }
    }

    public function rollBack(): bool
    {
        try {
            return $this->inner()->rollBack();
        } finally {
            $this->inTransaction = false;
        }
    }

    public function inTransaction(): bool
    {
        return $this->inner()->inTransaction();
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->inner()->lastInsertId($name);
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->inner()->getAttribute($attribute);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->inner()->setAttribute($attribute, $value);
    }
}
