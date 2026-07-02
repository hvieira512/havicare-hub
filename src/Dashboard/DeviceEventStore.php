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

    public function recent(string $imei, string $list): array
    {
        return array_values(array_filter(array_map(
            static fn (string $raw): ?array => json_decode($raw, true) ?: null,
            $this->redis->lrange($this->deviceListKey($imei, $list), 0, $this->limit - 1)
        ), 'is_array'));
    }

    private function key(string $suffix): string
    {
        return "{$this->prefix}:{$suffix}";
    }

    private function deviceListKey(string $imei, string $list): string
    {
        return $this->key("device:{$imei}:{$list}");
    }
}
