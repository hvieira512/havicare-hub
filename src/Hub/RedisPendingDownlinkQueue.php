<?php

namespace App\Hub;

use Predis\ClientInterface;

final class RedisPendingDownlinkQueue implements PendingDownlinkQueue
{
    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'hub:downlink',
    ) {
        $this->prefix = trim($this->prefix, ':');
    }

    public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $now = time();
        $downlink = new PendingDownlink(
            $imei,
            $this->dedupeKey($bytes, $command),
            $bytes,
            $command,
            $now,
            $now + $ttlSeconds,
        );

        $payload = json_encode([
            'imei' => $downlink->imei,
            'dedupeKey' => $downlink->dedupeKey,
            'payload' => base64_encode($downlink->bytes),
            'encoding' => 'base64',
            'command' => $downlink->command,
            'queuedAt' => $downlink->queuedAt,
            'expiresAt' => $downlink->expiresAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode pending downlink');
        }

        $this->redis->setex($this->entryKey($imei, $downlink->dedupeKey), $ttlSeconds, $payload);
        $this->redis->sadd($this->indexKey($imei), $downlink->dedupeKey);
        $this->redis->expire($this->indexKey($imei), $ttlSeconds);

        return $downlink;
    }

    public function pendingFor(string $imei): array
    {
        $items = [];
        $now = time();
        $dedupeKeys = $this->redis->smembers($this->indexKey($imei));

        foreach ($dedupeKeys as $dedupeKey) {
            $dedupeKey = (string)$dedupeKey;
            $raw = $this->redis->get($this->entryKey($imei, $dedupeKey));
            if (!is_string($raw) || $raw === '') {
                $this->redis->srem($this->indexKey($imei), $dedupeKey);
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || (string)($decoded['imei'] ?? '') !== $imei) {
                $this->redis->srem($this->indexKey($imei), $dedupeKey);
                continue;
            }

            $expiresAt = (int)($decoded['expiresAt'] ?? 0);
            if ($expiresAt > 0 && $expiresAt <= $now) {
                $this->redis->del([$this->entryKey($imei, $dedupeKey)]);
                $this->redis->srem($this->indexKey($imei), $dedupeKey);
                continue;
            }

            $bytes = base64_decode((string)($decoded['payload'] ?? ''), true);
            if ($bytes === false) {
                $this->redis->srem($this->indexKey($imei), $dedupeKey);
                continue;
            }

            $command = $decoded['command'] ?? null;
            $items[] = new PendingDownlink(
                $imei,
                $dedupeKey,
                $bytes,
                is_array($command) ? $command : null,
                (int)($decoded['queuedAt'] ?? 0),
                $expiresAt,
            );
        }

        usort(
            $items,
            static fn (PendingDownlink $a, PendingDownlink $b): int => $a->queuedAt <=> $b->queuedAt
        );

        return $items;
    }

    public function remove(PendingDownlink $downlink): void
    {
        $this->redis->del([$this->entryKey($downlink->imei, $downlink->dedupeKey)]);
        $this->redis->srem($this->indexKey($downlink->imei), $downlink->dedupeKey);
    }

    private function dedupeKey(string $bytes, ?array $command): string
    {
        $nativeType = is_array($command) ? (string)($command['nativeType'] ?? '') : '';
        if ($nativeType !== '') {
            $protocol = is_array($command) ? (string)($command['protocol'] ?? 'unknown') : 'unknown';
            return 'command:' . hash('sha256', $protocol . ':' . $nativeType);
        }

        return 'raw:' . hash('sha256', $bytes);
    }

    private function indexKey(string $imei): string
    {
        return "{$this->prefix}:{$imei}:index";
    }

    private function entryKey(string $imei, string $dedupeKey): string
    {
        return "{$this->prefix}:{$imei}:{$dedupeKey}";
    }
}
