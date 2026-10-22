<?php

declare(strict_types=1);

namespace Hub\Device;

use Predis\ClientInterface;

final class RedisPendingDownlinkQueue
{
    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'hub:downlink',
    ) {
        $this->prefix = trim($this->prefix, ':');
    }

    /** @param array<string, mixed>|null $command o comando que o downlink transporta, para registo */
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
            'payload' => base64_encode($downlink->bytes),
            'command' => $downlink->command,
            'queuedAt' => $downlink->queuedAt,
            // O segundo não chega para ordenar o que um só pedido pôs em fila.
            'queuedAtMicro' => microtime(true),
            'expiresAt' => $downlink->expiresAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode pending downlink');
        }

        $this->redis->hset($this->key($imei), $downlink->dedupeKey, $payload);
        // O hash vive enquanto viver a entrada que mais tarde expira; cada uma expira pelo `expiresAt`.
        $lastExpiry = max(array_map(
            static fn (string $raw): int => (int)(json_decode($raw, true)['expiresAt'] ?? 0),
            $this->redis->hgetall($this->key($imei)),
        ));
        $this->redis->expire($this->key($imei), max(1, $lastExpiry - $now));

        return $downlink;
    }

    /** @return array<int, PendingDownlink> */
    public function pendingFor(string $imei): array
    {
        $items = [];
        $order = [];
        $now = time();
        foreach ($this->redis->hgetall($this->key($imei)) as $dedupeKey => $raw) {
            $dedupeKey = (string)$dedupeKey;
            $decoded = json_decode((string)$raw, true);
            $expiresAt = (int)($decoded['expiresAt'] ?? 0);
            $bytes = base64_decode((string)($decoded['payload'] ?? ''), true);
            if (!is_array($decoded) || ($expiresAt > 0 && $expiresAt <= $now) || $bytes === false) {
                $this->redis->hdel($this->key($imei), [$dedupeKey]);
                continue;
            }

            $command = $decoded['command'] ?? null;
            $order[$dedupeKey] = (float)($decoded['queuedAtMicro'] ?? $decoded['queuedAt'] ?? 0);
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
            static fn (PendingDownlink $a, PendingDownlink $b): int => $order[$a->dedupeKey] <=> $order[$b->dedupeKey]
        );

        return $items;
    }

    public function remove(PendingDownlink $downlink): void
    {
        $this->redis->hdel($this->key($downlink->imei), [$downlink->dedupeKey]);
    }

    /** @param array<string, mixed>|null $command */
    private function dedupeKey(string $bytes, ?array $command): string
    {
        $operationId = is_array($command) ? (string)($command['operationId'] ?? '') : '';
        if ($operationId !== '') {
            return 'operation:' . hash('sha256', $operationId);
        }

        // O valor faz parte da identidade: fazer vibrar a pulseira e mandá-la parar são ambos
        // `config:find_device`.
        $value = is_array($command) && isset($command['payload'])
            ? '|' . json_encode($command['payload'], JSON_THROW_ON_ERROR)
            : '';

        $nativeType = is_array($command) ? (string)($command['nativeType'] ?? '') : '';
        if ($nativeType !== '') {
            $protocol = is_array($command) ? (string)($command['protocol'] ?? 'unknown') : 'unknown';
            return 'command:' . hash('sha256', $protocol . ':' . $nativeType . $value);
        }

        return 'raw:' . hash('sha256', $bytes . $value);
    }

    /** Um hash por aparelho, com uma entrada por chave de deduplicação. */
    private function key(string $imei): string
    {
        return "{$this->prefix}:{$imei}";
    }
}
