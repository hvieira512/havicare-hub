<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\ReconnectingPdo;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class ReconnectingPdoTest extends TestCase
{
    /** @param \Closure(int): PDO $perCall */
    private function connector(\Closure $perCall, int &$calls): \Closure
    {
        return static function () use ($perCall, &$calls): PDO {
            $calls++;
            return $perCall($calls);
        };
    }

    private function liveSqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('ok')");
        return $pdo;
    }

    public function testRetriesAReadAfterTheConnectionIsLost(): void
    {
        $calls = 0;
        $db = new ReconnectingPdo($this->connector(function (int $call): PDO {
            if ($call === 1) {
                return new class extends PDO {
                    public function __construct()
                    {
                    }
                    public function prepare(string $query, array $options = []): PDOStatement|false
                    {
                        throw new PDOException('SQLSTATE[HY000]: MySQL server has gone away');
                    }
                };
            }
            return $this->liveSqlite();
        }, $calls));

        $stmt = $db->prepare('SELECT v FROM t');
        $stmt->execute();

        self::assertSame('ok', $stmt->fetchColumn());
        self::assertSame(2, $calls, 'deveria ter reconectado uma vez e repetido a leitura');
    }

    public function testDoesNotRetryAWriteButRecoversForTheNextCall(): void
    {
        $calls = 0;
        $db = new ReconnectingPdo($this->connector(function (int $call): PDO {
            if ($call === 1) {
                return new class extends PDO {
                    public function __construct()
                    {
                    }
                    public function exec(string $statement): int|false
                    {
                        throw new PDOException('SQLSTATE[HY000]: MySQL server has gone away');
                    }
                };
            }
            return $this->liveSqlite();
        }, $calls));

        try {
            $db->exec("INSERT INTO t (v) VALUES ('x')");
            self::fail('a escrita deveria ter re-lançado, não sido repetida');
        } catch (PDOException) {
            // esperado
        }
        self::assertSame(1, $calls, 'a escrita não se repete na mesma chamada');

        // A chamada seguinte já apanha a ligação fresca.
        self::assertSame('ok', $db->query('SELECT v FROM t')->fetchColumn());
        self::assertSame(2, $calls, 'reconectou para a chamada seguinte');
    }
}
