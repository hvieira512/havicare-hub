<?php

namespace App\Redis;

use App\Log\Logger;

class Client
{
    private ?\Redis $redis = null;
    private bool $available = false;
    private string $nodeId;

    public function __construct(array $config)
    {
        $host = getenv('REDIS_HOST') ?: ($config['host'] ?? '127.0.0.1');
        $port = (int)(getenv('REDIS_PORT') ?: ($config['port'] ?? 6379));
        $password = $config['password'] ?? null;
        $dbIndex = (int)($config['database'] ?? 0);

        $this->nodeId = gethostname() ?: 'node-' . bin2hex(random_bytes(4));

        if (!extension_loaded('redis')) {
            Logger::channel('redis')->warning("The 'redis' extension is not available. Redis features are disabled.");
            return;
        }

        try {
            $this->redis = new \Redis();
            $this->redis->connect($host, $port, 2.0);
            if ($password) {
                $this->redis->auth($password);
            }
            if ($dbIndex > 0) {
                $this->redis->select($dbIndex);
            }
            $this->redis->ping();
            $this->available = true;
            Logger::channel('redis')->info("Connected to $host:$port (node: {$this->nodeId})");
        } catch (\Throwable $e) {
            $this->redis = null;
            $this->available = false;
            Logger::channel('redis')->warning("Redis connection unavailable (" . $e->getMessage() . ")");
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    // --- Device Registry ---

    public function deviceSetOnline(string $imei): void
    {
        if (!$this->available) return;
        try {
            $this->redis->hSet('device:online', $imei, $this->nodeId);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("deviceSetOnline: {$e->getMessage()}");
        }
    }

    public function deviceSetOffline(string $imei): void
    {
        if (!$this->available) return;
        try {
            $this->redis->hDel('device:online', $imei);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("deviceSetOffline: {$e->getMessage()}");
        }
    }

    public function deviceGetNode(string $imei): ?string
    {
        if (!$this->available) return null;
        try {
            $node = $this->redis->hGet('device:online', $imei);
            return $node === false ? null : $node;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getAllOnlineDevices(): array
    {
        if (!$this->available) return [];
        try {
            $result = $this->redis->hGetAll('device:online');
            return $result ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // --- Stream helpers ---

    private function pushToStream(string $stream, array $data, int $maxLen = 5000): string
    {
        if (!$this->available) return '0-0';
        try {
            return $this->redis->xAdd($stream, '*', $data, $maxLen);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("{$stream}Push: {$e->getMessage()}");
            return '0-0';
        }
    }

    private function readFromStream(string $stream, string $lastId, int $count, callable $mapper): array
    {
        if (!$this->available) return [];
        try {
            $streams = $this->redis->xRead([$stream => $lastId], $count);
            if (!$streams) return [];

            $results = [];
            foreach ($streams as $streamName => $entries) {
                foreach ($entries as $id => $data) {
                    $data['streamId'] = $id;
                    $results[] = $mapper($data);
                }
            }
            return $results;
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("read{$stream}: {$e->getMessage()}");
            return [];
        }
    }

    // --- Events Stream ---

    public function eventPush(array $event): string
    {
        return $this->pushToStream('events', [
            'imei' => $event['imei'],
            'native_type' => $event['nativeType'],
            'feature' => $event['feature'] ?? '',
            'native_payload' => json_encode($event['nativePayload']),
            'received_at' => $event['receivedAt'],
        ], 10000);
    }

    public function readEvents(string $lastId, int $count = 50): array
    {
        return $this->readFromStream('events', $lastId, $count,
            fn(array $data): array => [
                'streamId' => $data['streamId'],
                'imei' => $data['imei'],
                'nativeType' => $data['native_type'],
                'feature' => $data['feature'] ?: null,
                'nativePayload' => json_decode($data['native_payload'], true) ?? [],
                'receivedAt' => (int)$data['received_at'],
            ]
        );
    }

    // --- Device Status Stream ---

    public function statusPush(array $status): string
    {
        return $this->pushToStream('status', [
            'imei' => $status['imei'],
            'state' => $status['state'],
            'reason' => $status['reason'] ?? '',
            'protocol' => $status['protocol'] ?? '',
            'timestamp' => (string)($status['timestamp'] ?? (int)round(microtime(true) * 1000)),
        ]);
    }

    public function readStatus(string $lastId, int $count = 50): array
    {
        return $this->readFromStream('status', $lastId, $count,
            fn(array $data): array => [
                'streamId' => $data['streamId'],
                'imei' => $data['imei'] ?? '',
                'state' => $data['state'] ?? '',
                'reason' => $data['reason'] !== '' ? $data['reason'] : null,
                'protocol' => $data['protocol'] !== '' ? $data['protocol'] : null,
                'timestamp' => isset($data['timestamp']) ? (int)$data['timestamp'] : (int)round(microtime(true) * 1000),
            ]
        );
    }

    // --- Errors Stream ---

    public function errorPush(array $error): string
    {
        return $this->pushToStream('errors', [
            'imei' => $error['imei'] ?? '',
            'code' => $error['code'] ?? '',
            'message' => $error['message'] ?? '',
            'command' => $error['command'] ?? '',
            'protocol' => $error['protocol'] ?? '',
            'timestamp' => (string)($error['timestamp'] ?? (int)round(microtime(true) * 1000)),
        ]);
    }

    public function readErrors(string $lastId, int $count = 50): array
    {
        return $this->readFromStream('errors', $lastId, $count,
            fn(array $data): array => [
                'streamId' => $data['streamId'],
                'imei' => $data['imei'] ?? '',
                'code' => $data['code'] ?? '',
                'message' => $data['message'] ?? '',
                'command' => $data['command'] !== '' ? $data['command'] : null,
                'protocol' => $data['protocol'] !== '' ? $data['protocol'] : null,
                'timestamp' => isset($data['timestamp']) ? (int)$data['timestamp'] : (int)round(microtime(true) * 1000),
            ]
        );
    }

    // --- Command State Stream ---

    public function commandStatePush(array $state): string
    {
        return $this->pushToStream('command_state', [
            'imei' => $state['imei'] ?? '',
            'state' => $state['state'] ?? '',
            'type' => $state['type'] ?? '',
            'feature' => $state['feature'] ?? '',
            'request_id' => $state['requestId'] ?? '',
            'ident' => $state['ident'] ?? '',
            'reason' => $state['reason'] ?? '',
            'protocol' => $state['protocol'] ?? '',
            'timestamp' => (string)($state['timestamp'] ?? (int)round(microtime(true) * 1000)),
        ]);
    }

    public function readCommandState(string $lastId, int $count = 50): array
    {
        return $this->readFromStream('command_state', $lastId, $count,
            fn(array $data): array => [
                'streamId' => $data['streamId'],
                'imei' => $data['imei'] ?? '',
                'state' => $data['state'] ?? '',
                'type' => $data['type'] !== '' ? $data['type'] : null,
                'feature' => $data['feature'] !== '' ? $data['feature'] : null,
                'requestId' => $data['request_id'] !== '' ? $data['request_id'] : null,
                'ident' => $data['ident'] !== '' ? $data['ident'] : null,
                'reason' => $data['reason'] !== '' ? $data['reason'] : null,
                'protocol' => $data['protocol'] !== '' ? $data['protocol'] : null,
                'timestamp' => isset($data['timestamp']) ? (int)$data['timestamp'] : (int)round(microtime(true) * 1000),
            ]
        );
    }

    // --- Consumer Groups (worker) ---

    public function xGroupCreate(string $group, string $stream, string $id = '0', bool $mkStream = true): bool
    {
        if (!$this->available) return false;
        try {
            $this->redis->xGroup('CREATE', $stream, $group, $id, $mkStream);
            return true;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'BUSYGROUP')) {
                return true;
            }
            Logger::channel('redis')->error("xGroupCreate: {$e->getMessage()}");
            return false;
        }
    }

    public function xReadGroup(string $group, string $consumer, int $count = 10, int $blockMs = 2000): array
    {
        if (!$this->available) return [];
        try {
            $result = $this->redis->xReadGroup($group, $consumer, ['events' => '>'], $count, $blockMs);
            if (!$result) return [];

            $events = [];
            foreach ($result as $streamName => $entries) {
                foreach ($entries as $id => $data) {
                    $events[] = [
                        'streamId' => $id,
                        'stream' => $streamName,
                        'imei' => $data['imei'],
                        'nativeType' => $data['native_type'],
                        'feature' => $data['feature'] ?? '',
                        'nativePayload' => json_decode($data['native_payload'], true) ?? [],
                        'receivedAt' => (int)$data['received_at'],
                    ];
                }
            }
            return $events;
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("xReadGroup: {$e->getMessage()}");
            return [];
        }
    }

    public function xAck(string $stream, string $group, array $ids): int
    {
        if (!$this->available) return 0;
        try {
            return $this->redis->xAck($stream, $group, $ids);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("xAck: {$e->getMessage()}");
            return 0;
        }
    }

    // --- Command Stream (API -> WS) ---

    public function commandPublish(array $command): string
    {
        if (!$this->available) return '';
        try {
            return $this->redis->xAdd('cmd:stream', '*', [
                'imei' => $command['imei'],
                'type' => $command['type'],
                'payload' => json_encode($command['data'] ?? []),
                'request_id' => $command['requestId'] ?? '',
                'feature' => $command['feature'] ?? '',
                'source' => $command['source'] ?? 'api',
                'timestamp' => (string)(int)round(microtime(true) * 1000),
            ], 5000);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("commandPublish: {$e->getMessage()}");
            return '';
        }
    }

    public function commandReadGroup(string $group, string $consumer, int $count = 10, int $blockMs = 2000): array
    {
        if (!$this->available) return [];
        try {
            $result = $this->redis->xReadGroup($group, $consumer, ['cmd:stream' => '>'], $count, $blockMs);
            if (!$result) return [];
            return $this->mapCommandEntries($result, false);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("commandReadGroup: {$e->getMessage()}");
            return [];
        }
    }

    public function commandReadPending(string $group, string $consumer, int $count = 10): array
    {
        if (!$this->available) return [];
        try {
            $result = $this->redis->xReadGroup($group, $consumer, ['cmd:stream' => '0'], $count, 1);
            if (!$result) return [];
            return $this->mapCommandEntries($result, true);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("commandReadPending: {$e->getMessage()}");
            return [];
        }
    }

    // --- Rate Limiting ---

    public function rateLimitCheck(string $key, int $maxPerMinute = 30): bool
    {
        if (!$this->available) return true;
        try {
            $redisKey = "ratelimit:$key:" . time();
            $count = $this->redis->incr($redisKey);
            if ($count === 1) {
                $this->redis->expire($redisKey, 62);
            }
            return $count <= $maxPerMinute;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function rateLimitMessage(string $imei): bool
    {
        return $this->rateLimitCheck("msg:$imei", 60);
    }

    public function keys(string $pattern): array
    {
        if (!$this->available) return [];
        try {
            $result = $this->redis->keys($pattern);
            return $result ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // --- Key-value helpers ---

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        if (!$this->available) return;
        try {
            if ($ttl !== null) {
                $this->redis->setEx($key, $ttl, $value);
            } else {
                $this->redis->set($key, $value);
            }
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("set({$key}): {$e->getMessage()}");
        }
    }

    public function get(string $key): ?string
    {
        if (!$this->available) return null;
        try {
            $val = $this->redis->get($key);
            return $val === false ? null : $val;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function del(string $key): void
    {
        if (!$this->available) return;
        try {
            $this->redis->del($key);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("del({$key}): {$e->getMessage()}");
        }
    }

    private function mapCommandEntries(array $result, bool $isPending): array
    {
        $commands = [];
        foreach ($result as $streamName => $entries) {
            foreach ($entries as $id => $data) {
                $commands[] = [
                    'streamId' => $id,
                    'stream' => $streamName,
                    'imei' => $data['imei'],
                    'type' => $data['type'],
                    'data' => json_decode($data['payload'], true) ?? [],
                    'requestId' => $data['request_id'] ?? '',
                    'feature' => $data['feature'] ?? '',
                    'source' => $data['source'] ?? '',
                    'isPending' => $isPending,
                ];
            }
        }
        return $commands;
    }
}
