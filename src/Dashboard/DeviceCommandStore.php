<?php

namespace Hub\Dashboard;

use Predis\ClientInterface;

final class DeviceCommandStore
{
    public function __construct(
        private ClientInterface $redis,
        private DeviceRuntimeStore $runtime,
        private int $limit = 100,
        private string $prefix = 'hub:dashboard',
        private ?DeviceConfigurationProjection $projection = null,
    ) {
        $this->prefix = trim($this->prefix, ':');
        $this->limit = max(1, $this->limit);
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
        $this->redis->hset($this->commandIndexKey(), $id, $imei);
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
            if ($this->projection !== null && isset($command['configKey'])) {
                $this->projection->markApplyStatus($imei, (string)$command['configKey'], 'acked', $id);
            }
            return;
        }
    }

    public function expireWaitingCommands(int $timeoutSeconds): void
    {
        $cutoff = time() - max(1, $timeoutSeconds);
        foreach ($this->runtime->devices() as $device) {
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

    public function findCommand(string $id): ?array
    {
        $imei = (string)($this->redis->hget($this->commandIndexKey(), $id) ?? '');
        if ($imei === '') {
            return null;
        }

        $raw = $this->redis->hget($this->commandHashKey($imei), $id);
        if (!is_string($raw)) {
            $this->redis->hdel($this->commandIndexKey(), $id);
            return null;
        }

        $command = json_decode($raw, true);
        if (is_array($command)) {
            return [
                'device' => $this->runtime->device($imei),
                'command' => $command,
            ];
        }

        return null;
    }

    private function key(string $suffix): string
    {
        return "{$this->prefix}:{$suffix}";
    }

    private function deviceListKey(string $imei, string $list): string
    {
        return $this->key("device:{$imei}:{$list}");
    }

    private function commandHashKey(string $imei): string
    {
        return $this->key("device:{$imei}:command-records");
    }

    private function commandIndexKey(): string
    {
        return $this->key('command-index');
    }
}
