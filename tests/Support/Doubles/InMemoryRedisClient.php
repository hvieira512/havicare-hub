<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Predis\ClientInterface;
use Predis\Command\CommandInterface;

/**
 * O substituto em memória do Predis, a cobrir o subconjunto de comandos que a dashboard
 * store actually issues.
 *
 * Num sítio só: em três cópias quase iguais, cada comando novo tinha de ser acrescentado três
 * vezes, e acrescentá-lo à lista de métodos mas não ao despachante falhava de uma forma que
 * só aparecia em execução.
 */
final class InMemoryRedisClient implements ClientInterface
{
    /** @var array<string, array<string, bool>> */
    private array $sets = [];

    /** @var array<string, array<string, string>> */
    private array $hashes = [];

    /** @var array<string, array<int, string>> */
    private array $lists = [];

    /** @var array<string, string> */
    private array $strings = [];

    /** @var array<string, int> */
    private array $stringExpiresAt = [];

    /** @var array<string, array<string, float>> */
    private array $sortedSets = [];

    public function getCommandFactory()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function getOptions()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function connect()
    {
    }

    public function disconnect()
    {
    }

    public function getConnection()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function pipeline(callable $callback): void
    {
        $callback($this);
    }

    public function __call($method, $arguments)
    {
        return match (strtolower((string)$method)) {
            'sadd' => $this->sadd((string)$arguments[0], (string)$arguments[1]),
            'srem' => $this->srem((string)$arguments[0], (string)$arguments[1]),
            'smembers' => $this->smembers((string)$arguments[0]),
            'hmset' => $this->hmset((string)$arguments[0], $arguments[1]),
            'hgetall' => $this->hgetall((string)$arguments[0]),
            'hset' => $this->hset((string)$arguments[0], (string)$arguments[1], (string)$arguments[2]),
            'hdel' => $this->hdel((string)$arguments[0], $arguments[1]),
            'hget' => $this->hget((string)$arguments[0], (string)$arguments[1]),
            'hmget' => $this->hmget((string)$arguments[0], (array)$arguments[1]),
            'lpush' => $this->lpush((string)$arguments[0], $arguments[1]),
            'ltrim' => $this->ltrim((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrange' => $this->lrange((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrem' => $this->lrem((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'zadd' => $this->zadd((string)$arguments[0], $arguments[1]),
            'zrem' => $this->zrem((string)$arguments[0], $arguments[1]),
            'zrangebyscore' => $this->zrangebyscore((string)$arguments[0], (string)$arguments[1], (string)$arguments[2]),
            'setex' => $this->setex((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'set' => $this->set($arguments),
            'get' => $this->get((string)$arguments[0]),
            'del' => $this->del($arguments[0]),
            'incr' => $this->incr((string)$arguments[0]),
            // O `expire` é fiel o suficiente sendo inerte: quem o usa põe a janela na própria
            // chave, e o prazo serve só para o Redis a sério não guardar janelas passadas.
            'expire' => 1,
            default => throw new \BadMethodCallException("Redis method {$method} is not implemented"),
        };
    }

    /** O contador que dá o número de ordem a cada entrada do histórico. */
    private function incr(string $key): int
    {
        $next = (int)($this->strings[$key] ?? '0') + 1;
        $this->strings[$key] = (string)$next;

        return $next;
    }

    private function sadd(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        $this->sets[$key][$member] = true;

        return $exists ? 0 : 1;
    }

    private function srem(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        unset($this->sets[$key][$member]);

        return $exists ? 1 : 0;
    }

    private function smembers(string $key): array
    {
        return array_keys($this->sets[$key] ?? []);
    }

    private function hmset(string $key, array $dictionary): string
    {
        $this->hashes[$key] = array_merge($this->hashes[$key] ?? [], array_map('strval', $dictionary));

        return 'OK';
    }

    private function hgetall(string $key): array
    {
        return $this->hashes[$key] ?? [];
    }

    private function hset(string $key, string $field, string $value): int
    {
        $exists = array_key_exists($field, $this->hashes[$key] ?? []);
        $this->hashes[$key][$field] = $value;

        return $exists ? 0 : 1;
    }

    private function hdel(string $key, $fields): int
    {
        $fields = is_array($fields) ? $fields : [$fields];
        $deleted = 0;
        foreach ($fields as $field) {
            $field = (string)$field;
            if (isset($this->hashes[$key][$field])) {
                unset($this->hashes[$key][$field]);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function hget(string $key, string $field): ?string
    {
        return $this->hashes[$key][$field] ?? null;
    }

    /** @return list<string|null> values in the order requested, null where absent */
    private function hmget(string $key, array $fields): array
    {
        return array_map(fn(string $field): ?string => $this->hashes[$key][$field] ?? null, $fields);
    }

    private function lpush(string $key, $values): int
    {
        $values = is_array($values) ? array_values(array_map('strval', $values)) : [(string)$values];
        $this->lists[$key] = array_merge($values, $this->lists[$key] ?? []);

        return count($this->lists[$key]);
    }

    private function ltrim(string $key, int $start, int $stop): string
    {
        $list = $this->lists[$key] ?? [];
        if ($stop < 0) {
            $stop = count($list) + $stop;
        }
        $length = max(0, $stop - $start + 1);
        $this->lists[$key] = array_slice($list, $start, $length);

        return 'OK';
    }

    private function lrange(string $key, int $start, int $stop): array
    {
        $list = $this->lists[$key] ?? [];
        if ($stop < 0) {
            $stop = count($list) + $stop;
        }
        $length = max(0, $stop - $start + 1);

        return array_slice($list, $start, $length);
    }

    private function lrem(string $key, int $count, string $value): int
    {
        $list = $this->lists[$key] ?? [];
        $removed = 0;
        $result = [];

        foreach ($list as $item) {
            if ($item === $value && ($count === 0 || $removed < abs($count))) {
                $removed++;
                continue;
            }
            $result[] = $item;
        }

        $this->lists[$key] = $result;

        return $removed;
    }

    private function zadd(string $key, array $members): int
    {
        $added = 0;
        foreach ($members as $member => $score) {
            if (!isset($this->sortedSets[$key][(string)$member])) {
                $added++;
            }
            $this->sortedSets[$key][(string)$member] = (float)$score;
        }

        return $added;
    }

    private function zrem(string $key, $members): int
    {
        $members = is_array($members) ? $members : [$members];
        $deleted = 0;
        foreach ($members as $member) {
            $member = (string)$member;
            if (isset($this->sortedSets[$key][$member])) {
                unset($this->sortedSets[$key][$member]);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function zrangebyscore(string $key, string $min, string $max): array
    {
        $items = $this->sortedSets[$key] ?? [];
        $lower = $min === '-inf' ? -INF : (float)$min;
        $upper = $max === '+inf' ? INF : (float)$max;
        $filtered = array_filter($items, static fn(float $score): bool => $score >= $lower && $score <= $upper);
        asort($filtered, SORT_NUMERIC);

        return array_keys($filtered);
    }

    private function setex(string $key, int $seconds, string $value): string
    {
        $this->strings[$key] = $value;
        $this->stringExpiresAt[$key] = time() + max(1, $seconds);

        return 'OK';
    }

    /**
     * O `set` do Predis com as opções variádicas que o hub usa: `EX <segundos>` e `NX`.
     *
     * @param array<int, mixed> $arguments
     */
    private function set(array $arguments): ?string
    {
        $key = (string)$arguments[0];
        $value = (string)$arguments[1];
        $ttl = null;
        $onlyIfAbsent = false;
        for ($i = 2, $n = count($arguments); $i < $n; $i++) {
            $option = strtoupper((string)$arguments[$i]);
            if ($option === 'EX' && isset($arguments[$i + 1])) {
                $ttl = (int)$arguments[++$i];
            } elseif ($option === 'NX') {
                $onlyIfAbsent = true;
            }
        }

        if ($onlyIfAbsent && $this->get($key) !== null) {
            return null;
        }

        $this->strings[$key] = $value;
        if ($ttl !== null) {
            $this->stringExpiresAt[$key] = time() + max(1, $ttl);
        } else {
            unset($this->stringExpiresAt[$key]);
        }

        return 'OK';
    }

    /** Segundos até uma chave expirar, ou null se não tem prazo. Para os testes verificarem o TTL. */
    public function ttlFor(string $key): ?int
    {
        return isset($this->stringExpiresAt[$key]) ? $this->stringExpiresAt[$key] - time() : null;
    }

    private function get(string $key): ?string
    {
        if (isset($this->stringExpiresAt[$key]) && $this->stringExpiresAt[$key] <= time()) {
            unset($this->strings[$key], $this->stringExpiresAt[$key]);
            return null;
        }

        return $this->strings[$key] ?? null;
    }

    private function del($keys): int
    {
        $keys = is_array($keys) ? $keys : [$keys];
        $deleted = 0;
        foreach ($keys as $key) {
            $key = (string)$key;
            foreach (['hashes', 'lists', 'strings', 'sets', 'sortedSets'] as $bucket) {
                if (isset($this->{$bucket}[$key])) {
                    unset($this->{$bucket}[$key]);
                    $deleted++;
                }
            }
            unset($this->stringExpiresAt[$key]);
        }

        return $deleted;
    }
}
