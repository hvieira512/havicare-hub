<?php

namespace Hub\Dashboard;

use Hub\Command\DeviceConfigurationCatalog;
use Predis\ClientInterface;

final class DashboardStore
{
    private ?DatabaseStore $db = null;

    public function __construct(
        private ClientInterface $redis,
        private int $limit = 100,
        private string $prefix = 'hub:dashboard',
    ) {
        $this->prefix = trim($this->prefix, ':');
        $this->limit = max(1, $this->limit);
    }

    public function setDatabaseStore(?DatabaseStore $db): void
    {
        $this->db = $db;
    }

    public function registerDevice(string $imei, string $supplier, string $model): void
    {
        $this->redis->sadd($this->key('devices'), $imei);
        $this->redis->hmset($this->deviceKey($imei), [
            'imei' => $imei,
            'supplier' => $supplier,
            'model' => $model,
        ]);
    }

    public function deleteDevice(string $imei): void
    {
        $this->redis->srem($this->key('devices'), $imei);
        $this->redis->del([
            $this->deviceKey($imei),
            $this->deviceListKey($imei, 'raw'),
            $this->deviceListKey($imei, 'telemetry'),
            $this->deviceListKey($imei, 'events'),
            $this->deviceListKey($imei, 'commands'),
            $this->commandHashKey($imei),
        ]);
    }

    public function deviceSeen(string $imei, array $fields): void
    {
        $this->redis->sadd($this->key('devices'), $imei);
        $this->redis->hmset($this->deviceKey($imei), array_merge($fields, [
            'imei' => $imei,
            'lastSeenAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]));
    }

    public function deviceOffline(string $imei): void
    {
        $this->redis->hmset($this->deviceKey($imei), [
            'imei' => $imei,
            'online' => '0',
            'lastStateAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
    }

    public function append(string $imei, string $list, array $payload): void
    {
        $payload['recordedAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }
        $key = $this->deviceListKey($imei, $list);
        $this->redis->lpush($key, [$encoded]);
        $this->redis->ltrim($key, 0, $this->limit - 1);

        if ($this->db !== null) {
            if ($list === 'telemetry' && ($payload['type'] ?? '') === 'device_config') {
                $device = isset($payload['device']) && is_array($payload['device']) ? $payload['device'] : [];
                $source = isset($payload['source']) && is_array($payload['source']) ? $payload['source'] : [];
                $nativeType = (string)($source['nativeType'] ?? 'device_config');
                $protocol = (string)($source['protocol'] ?? '');
                $key = $nativeType;
                foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                    if (in_array($nativeType, $entry['expectedReplyTypes'] ?? [], true)) {
                        $key = (string)$entry['key'];
                        break;
                    }
                }
                $this->db->saveReportedConfiguration(
                    $imei,
                    $key,
                    $protocol,
                    (string)($device['supplier'] ?? ''),
                    (string)($device['model'] ?? ''),
                    $nativeType,
                    $payload
                );
            }
            match ($list) {
                'telemetry' => $this->db->appendTelemetry($imei, $payload['type'] ?? 'unknown', $payload),
                'events' => $this->db->appendEvent($imei, $payload['type'] ?? 'unknown', $payload),
                default => $this->db->appendRaw($imei, $payload),
            };
        }
    }

    public function recordCommand(string $imei, string $id, array $record): void
    {
        $record['id'] = $id;
        $record['updatedAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }
        $this->redis->hset($this->commandHashKey($imei), $id, $encoded);
        $this->redis->lrem($this->deviceListKey($imei, 'commands'), 0, $id);
        $this->redis->lpush($this->deviceListKey($imei, 'commands'), [$id]);
        $this->redis->ltrim($this->deviceListKey($imei, 'commands'), 0, $this->limit - 1);
    }

    public function markLatestCommand(string $imei, string $nativeType, array $fields): void
    {
        foreach ($this->commands($imei) as $command) {
            if (($command['nativeType'] ?? '') !== $nativeType) {
                continue;
            }
            if (in_array((string)($command['status'] ?? ''), ['acked', 'failed', 'dropped'], true)) {
                continue;
            }
            $this->recordCommand($imei, (string)$command['id'], array_merge($command, $fields));
            return;
        }
    }

    public function markCommandReply(string $imei, string $replyNativeType): void
    {
        foreach ($this->commands($imei) as $command) {
            if (!in_array((string)($command['status'] ?? ''), ['waiting'], true)) {
                continue;
            }
            $expected = $command['expectedReplyTypes'] ?? [];
            if (!is_array($expected) || !in_array($replyNativeType, $expected, true)) {
                continue;
            }
            $id = (string)$command['id'];
            $this->recordCommand($imei, $id, array_merge($command, [
                'status' => 'acked',
                'ackedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'replyNativeType' => $replyNativeType,
            ]));
            if ($this->db !== null && isset($command['configKey'])) {
                $this->db->markConfigurationApplyStatus($imei, (string)$command['configKey'], 'acked', $id);
            }
            return;
        }
    }

    public function expireWaitingCommands(int $timeoutSeconds): void
    {
        $cutoff = time() - max(1, $timeoutSeconds);
        foreach ($this->devices() as $device) {
            $imei = (string)($device['imei'] ?? '');
            foreach ($this->commands($imei) as $command) {
                if (($command['status'] ?? '') !== 'waiting') {
                    continue;
                }
                $sentAt = strtotime((string)($command['sentAt'] ?? '')) ?: 0;
                if ($sentAt > 0 && $sentAt <= $cutoff) {
                    $this->recordCommand($imei, (string)$command['id'], array_merge($command, ['status' => 'failed', 'error' => 'response_timeout']));
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function devices(): array
    {
        $devices = [];
        foreach ($this->redis->smembers($this->key('devices')) as $imei) {
            $data = $this->redis->hgetall($this->deviceKey((string)$imei));
            if ($data !== []) {
                $devices[] = $this->normalizeDevice($data);
            }
        }
        usort($devices, static fn (array $a, array $b): int => strcmp((string)($a['imei'] ?? ''), (string)($b['imei'] ?? '')));
        return $devices;
    }

    public function device(string $imei): array
    {
        return $this->normalizeDevice($this->redis->hgetall($this->deviceKey($imei)) ?: ['imei' => $imei]);
    }

    public function recent(string $imei, string $list): array
    {
        return array_values(array_filter(array_map(
            static fn (string $raw): ?array => json_decode($raw, true) ?: null,
            $this->redis->lrange($this->deviceListKey($imei, $list), 0, $this->limit - 1)
        ), 'is_array'));
    }

    public function commands(string $imei): array
    {
        $commands = [];
        foreach ($this->redis->lrange($this->deviceListKey($imei, 'commands'), 0, $this->limit - 1) as $id) {
            $raw = $this->redis->hget($this->commandHashKey($imei), (string)$id);
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $commands[] = $decoded;
                }
            }
        }
        return $commands;
    }

    private function normalizeDevice(array $data): array
    {
        $data['online'] = ((string)($data['online'] ?? '0')) === '1';
        return $data;
    }

    private function key(string $suffix): string
    {
        return "{$this->prefix}:{$suffix}";
    }

    private function deviceKey(string $imei): string
    {
        return $this->key("device:{$imei}");
    }

    private function deviceListKey(string $imei, string $list): string
    {
        return $this->key("device:{$imei}:{$list}");
    }

    private function commandHashKey(string $imei): string
    {
        return $this->key("device:{$imei}:command-records");
    }
}
