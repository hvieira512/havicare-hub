<?php

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\DashboardStore;
use Hub\Command\DeviceCommandCatalog;
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

    public function testRetryWaitingCommandsResendsRetryableCommands(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');
        $store->recordCommand('861265061009822', 'cmd-1', [
            'status' => 'waiting',
            'retryable' => true,
            'bytes' => 'IWBP76,1',
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $calls = [];
        $store->retryWaitingCommands(60, 3600, 3, function (string $imei, string $bytes, array $command) use (&$calls): string {
            $calls[] = [$imei, $bytes, $command['id'] ?? null];
            return 'sent';
        });

        self::assertCount(1, $calls);
        self::assertSame(['861265061009822', 'IWBP76,1', 'cmd-1'], $calls[0]);

        $command = $store->commands('861265061009822')[0] ?? [];
        self::assertSame('waiting', $command['status'] ?? null);
        self::assertSame(2, $command['attempts'] ?? null);
        self::assertNotEmpty($command['lastAttemptAt'] ?? null);
        self::assertNotEmpty($command['nextRetryAt'] ?? null);
    }

    public function testBinaryCommandBytesAreStoredAsBase64AndDecodedForRetry(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('868705080304962', 'Wonlex', 'HW20PRO');
        $wireBytes = "\xfc\xaf\x00\x05hello";
        $store->recordCommand('868705080304962', 'cmd-wonlex', [
            'status' => 'waiting',
            'retryable' => true,
            'bytes' => $wireBytes,
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $command = $store->commands('868705080304962')[0] ?? [];
        self::assertSame(base64_encode($wireBytes), $command['bytes'] ?? null);
        self::assertSame('base64', $command['bytesEncoding'] ?? null);
        self::assertIsString(json_encode($command));

        $calls = [];
        $store->retryWaitingCommands(60, 3600, 3, static function (string $imei, string $bytes) use (&$calls): string {
            $calls[] = [$imei, $bytes];
            return 'sent';
        });

        self::assertSame([['868705080304962', $wireBytes]], $calls);
    }

    public function testWonlexRepliesAreCorrelatedByIdentAndRef(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard');
        $imei = '868705080304962';
        $store->registerDevice($imei, 'Wonlex', 'HW20PRO');

        $first = DeviceCommandCatalog::buildDownlink('wonlex-json', $imei, 'dnHeartRate', [], ['ident' => 111111]);
        $second = DeviceCommandCatalog::buildDownlink('wonlex-json', $imei, 'dnHeartRate', [], ['ident' => 222222]);
        foreach ([['one', $first], ['two', $second]] as [$id, $bytes]) {
            $store->recordCommand($imei, $id, [
                'status' => 'waiting',
                'protocol' => 'wonlex-json',
                'nativeType' => 'dnHeartRate',
                'expectedReplyTypes' => ['upHeartRate', 'upBatch'],
                'bytes' => $bytes,
            ]);
        }

        $store->markCommandReply($imei, 'upHeartRate', 222222, 'w:update');
        $commands = array_column($store->commands($imei), null, 'id');

        self::assertSame('waiting', $commands['one']['status']);
        self::assertSame('acked', $commands['two']['status']);
        self::assertSame(222222, $commands['two']['replyIdent']);
    }

    public function testFourPTouchLssetReplyAcknowledgesSensitivityCommand(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard');
        $imei = '864504816144000';
        $store->registerDevice($imei, '4P Touch', 'D46', deviceId: '4504816144');
        $store->recordCommand($imei, 'lsset-command', [
            'status' => 'waiting',
            'protocol' => 'four-p-touch',
            'nativeType' => 'LSSET',
            'expectedReplyTypes' => ['LSSET'],
            'bytes' => '[3G*4504816144*0009*LSSET,5+6]',
        ]);

        $store->markCommandReply($imei, 'LSSET', '4504816144', 'w:update');
        $command = $store->commands($imei)[0] ?? [];

        self::assertSame('acked', $command['status'] ?? null);
        self::assertSame('LSSET', $command['replyNativeType'] ?? null);
        self::assertSame('4504816144', $command['replyIdent'] ?? null);
    }

    public function testRetryWaitingCommandsDispatchesQueuedRetryableCommands(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861728087743062', '4P Touch', 'D41');
        $store->recordCommand('861728087743062', 'cmd-queued', [
            'status' => 'queued',
            'retryable' => true,
            'bytes' => '[3G*2808774306*0009*LSSET,3+6]',
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $calls = [];
        $store->retryWaitingCommands(60, 3600, 3, function (string $imei, string $bytes, array $command) use (&$calls): string {
            $calls[] = [$imei, $bytes, $command['id'] ?? null];
            return 'sent';
        });

        self::assertSame([
            ['861728087743062', '[3G*2808774306*0009*LSSET,3+6]', 'cmd-queued'],
        ], $calls);

        $command = $store->commands('861728087743062')[0] ?? [];
        self::assertSame('waiting', $command['status'] ?? null);
        self::assertSame(1, $command['attempts'] ?? null);
        self::assertNotEmpty($command['sentAt'] ?? null);
        self::assertGreaterThan(time(), strtotime((string)($command['nextRetryAt'] ?? '')));
    }

    public function testQueuedRedispatchDoesNotConsumeAttemptsWhileDeviceRemainsOffline(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861728087743062', '4P Touch', 'D41');
        $store->recordCommand('861728087743062', 'cmd-queued', [
            'status' => 'queued',
            'retryable' => true,
            'bytes' => '[3G*2808774306*0009*LSSET,3+6]',
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $store->retryWaitingCommands(60, 3600, 3, static fn(): string => 'queued');

        $command = $store->commands('861728087743062')[0] ?? [];
        self::assertSame('queued', $command['status'] ?? null);
        self::assertSame(1, $command['attempts'] ?? null);
        self::assertNotEmpty($command['lastAttemptAt'] ?? null);
        self::assertGreaterThan(time(), strtotime((string)($command['nextRetryAt'] ?? '')));
    }

    public function testQueuedRedispatchIgnoresSentAttemptLimitUntilFirstDelivery(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861728087743062', '4P Touch', 'D41');
        $store->recordCommand('861728087743062', 'cmd-queued', [
            'status' => 'queued',
            'retryable' => true,
            'bytes' => '[3G*2808774306*0009*LSSET,3+6]',
            'attempts' => 3,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $calls = 0;
        $store->retryWaitingCommands(60, 3600, 3, static function () use (&$calls): string {
            $calls++;
            return 'sent';
        });

        self::assertSame(1, $calls);
        $command = $store->commands('861728087743062')[0] ?? [];
        self::assertSame('waiting', $command['status'] ?? null);
        self::assertSame(3, $command['attempts'] ?? null);
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
