<?php

namespace Hub\Dashboard;

use Predis\ClientInterface;

final class DeviceEventStore
{
    public function __construct(
        private ClientInterface $redis,
        private int $limit = 100,
        private string $prefix = 'hub:dashboard',
        private ?DeviceConfigurationProjection $projection = null,
    ) {
        $this->prefix = trim($this->prefix, ':');
        $this->limit = max(1, $this->limit);
    }

    public function append(string $imei, string $list, array $payload): void
    {
        // O número de ordem é o que permite ao stream mandar só o que é novo. O `recordedAt`
        // não servia -- tem resolução de um segundo e um radar publica vinte por segundo --,
        // e o índice da lista anda com cada `lpush`.
        $payload['seq'] = (int)$this->redis->incr($this->sequenceKey($imei, $list));
        $payload['recordedAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }
        $key = $this->deviceListKey($imei, $list);
        $this->redis->pipeline(function ($pipe) use ($key, $encoded): void {
            $pipe->lpush($key, [$encoded]);
            $pipe->ltrim($key, 0, $this->limit - 1);
        });

        if ($this->projection !== null && $list === 'telemetry' && ($payload['type'] ?? '') === 'device_config') {
            $device = isset($payload['device']) && is_array($payload['device']) ? $payload['device'] : [];
            $source = isset($payload['source']) && is_array($payload['source']) ? $payload['source'] : [];
            $this->projection->saveReported(
                $imei,
                (string)($source['protocol'] ?? ''),
                (string)($device['supplier'] ?? ''),
                (string)($device['model'] ?? ''),
                (string)($source['nativeType'] ?? 'device_config'),
                $payload
            );
        }
    }

    /**
     * As entradas mais recentes, da mais nova para a mais velha. Com `$sinceSeq` maior que
     * zero devolve só o que entrou depois; as gravadas antes de haver `seq` contam como
     * anteriores a qualquer cursor e só aparecem no instantâneo inicial.
     */
    public function recent(string $imei, string $list, int $sinceSeq = 0): array
    {
        $entries = array_values(array_filter(array_map(
            static fn (string $raw): ?array => json_decode($raw, true) ?: null,
            $this->redis->lrange($this->deviceListKey($imei, $list), 0, $this->limit - 1)
        ), 'is_array'));

        if ($sinceSeq <= 0) {
            return $entries;
        }

        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => (int)($entry['seq'] ?? 0) > $sinceSeq
        ));
    }

    /** O número de ordem da entrada mais recente, que é o cursor a devolver ao cliente. */
    public function latestSequence(string $imei, string $list): int
    {
        return (int)($this->redis->get($this->sequenceKey($imei, $list)) ?? 0);
    }

    private function key(string $suffix): string
    {
        return "{$this->prefix}:{$suffix}";
    }

    private function deviceListKey(string $imei, string $list): string
    {
        return $this->key("device:{$imei}:{$list}");
    }

    private function sequenceKey(string $imei, string $list): string
    {
        return $this->key("device:{$imei}:{$list}:seq");
    }
}
