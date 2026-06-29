<?php

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\DashboardStore;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;

final class DashboardStoreTest extends TestCase
{
    public function testDeleteDeviceRemovesDeviceListEntryAndDeviceData(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');

        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');
        $store->append('861265061009822', 'raw', ['payload' => 'IWAP00']);
        $store->append('861265061009822', 'telemetry', ['type' => 'heartbeat']);
        $store->append('861265061009822', 'events', ['type' => 'fall']);
        $store->recordCommand('861265061009822', 'cmd-1', ['status' => 'waiting']);

        self::assertCount(1, $store->devices());
        self::assertNotSame([], $store->recent('861265061009822', 'raw'));
        self::assertNotSame([], $store->commands('861265061009822'));

        $store->deleteDevice('861265061009822');

        self::assertSame([], $store->devices());
        self::assertSame([], $store->recent('861265061009822', 'raw'));
        self::assertSame([], $store->recent('861265061009822', 'telemetry'));
        self::assertSame([], $store->recent('861265061009822', 'events'));
        self::assertSame([], $store->commands('861265061009822'));
    }

    public function testExpireStaleDevicesMarksOldOnlineDevicesOffline(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');

        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');
        $store->deviceSeen('861265061009822', ['online' => '1']);
        $redis->hmset('test:dashboard:device:861265061009822', [
            'lastSeenAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 7200),
        ]);
        $redis->zadd('test:dashboard:online-devices-by-last-seen', ['861265061009822' => time() - 7200]);

        $store->expireStaleDevices(60);

        self::assertFalse($store->device('861265061009822')['online']);
    }

    public function testRegisterDevicePersistsDeviceTypeAndLicenseId(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');

        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE', 'radar', 12);

        $device = $store->device('861265061009822');
        self::assertSame('radar', $device['deviceType']);
        self::assertSame(12, $device['licenseId']);
    }
}

final class InMemoryRedisClient implements ClientInterface
{
    /** @var array<string, array<string, bool>> */
    private array $sets = [];

    /** @var array<string, array<string, string>> */
    private array $hashes = [];

    /** @var array<string, array<int, string>> */
    private array $lists = [];

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
            'lpush' => $this->lpush((string)$arguments[0], $arguments[1]),
            'ltrim' => $this->ltrim((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrange' => $this->lrange((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrem' => $this->lrem((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'zadd' => $this->zadd((string)$arguments[0], $arguments[1]),
            'zrem' => $this->zrem((string)$arguments[0], $arguments[1]),
            'zrangebyscore' => $this->zrangebyscore((string)$arguments[0], (string)$arguments[1], (string)$arguments[2]),
            'del' => $this->del($arguments[0]),
            default => throw new \BadMethodCallException("Redis method {$method} is not implemented"),
        };
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
        $exists = isset($this->hashes[$key][$field]);
        $this->hashes[$key][$field] = $value;

        return $exists ? 0 : 1;
    }

    private function hget(string $key, string $field): ?string
    {
        return $this->hashes[$key][$field] ?? null;
    }

    private function hdel(string $key, array|string $fields): int
    {
        $removed = 0;
        foreach ((array)$fields as $field) {
            if (isset($this->hashes[$key][(string)$field])) {
                unset($this->hashes[$key][(string)$field]);
                $removed++;
            }
        }

        return $removed;
    }

    private function lpush(string $key, array $values): int
    {
        $this->lists[$key] ??= [];
        foreach ($values as $value) {
            array_unshift($this->lists[$key], (string)$value);
        }

        return count($this->lists[$key]);
    }

    private function ltrim(string $key, int $start, int $stop): string
    {
        $this->lists[$key] = array_slice($this->lists[$key] ?? [], $start, $stop - $start + 1);

        return 'OK';
    }

    private function lrange(string $key, int $start, int $stop): array
    {
        return array_slice($this->lists[$key] ?? [], $start, $stop - $start + 1);
    }

    private function lrem(string $key, int $count, string $value): int
    {
        $removed = 0;
        $this->lists[$key] = array_values(array_filter(
            $this->lists[$key] ?? [],
            static function (string $item) use ($value, $count, &$removed): bool {
                if ($item !== $value || ($count > 0 && $removed >= $count)) {
                    return true;
                }
                $removed++;
                return false;
            }
        ));

        return $removed;
    }

    private function zadd(string $key, array $members): int
    {
        $added = 0;
        $this->sortedSets[$key] ??= [];
        foreach ($members as $member => $score) {
            $member = (string)$member;
            if (!isset($this->sortedSets[$key][$member])) {
                $added++;
            }
            $this->sortedSets[$key][$member] = (float)$score;
        }

        return $added;
    }

    private function zrem(string $key, array|string $members): int
    {
        $removed = 0;
        foreach ((array)$members as $member) {
            $member = (string)$member;
            if (isset($this->sortedSets[$key][$member])) {
                unset($this->sortedSets[$key][$member]);
                $removed++;
            }
        }

        return $removed;
    }

    private function zrangebyscore(string $key, string $min, string $max): array
    {
        $minScore = $min === '-inf' ? -INF : (float)$min;
        $maxScore = $max === '+inf' ? INF : (float)$max;
        $matches = [];
        foreach ($this->sortedSets[$key] ?? [] as $member => $score) {
            if ($score < $minScore || $score > $maxScore) {
                continue;
            }
            $matches[$member] = $score;
        }
        asort($matches, SORT_NUMERIC);

        return array_keys($matches);
    }

    private function del(array|string $keys): int
    {
        $removed = 0;
        foreach ((array)$keys as $key) {
            $removed += isset($this->hashes[$key]) || isset($this->lists[$key]) || isset($this->sets[$key]) || isset($this->sortedSets[$key]) ? 1 : 0;
            unset($this->hashes[$key], $this->lists[$key], $this->sets[$key], $this->sortedSets[$key]);
        }

        return $removed;
    }
}
