<?php

namespace Hub\Dashboard;

use Hub\Domain\DeviceMetadata;
use Predis\ClientInterface;

final class DeviceRuntimeStore
{
    public function __construct(
        private ClientInterface $redis,
        private int $limit = 100,
        private string $prefix = 'hub:dashboard',
    ) {
        $this->prefix = trim($this->prefix, ':');
        $this->limit = max(1, $this->limit);
    }

    public function registerDevice(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null'
    ): void {
        $payload = [
            'imei' => $imei,
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => DeviceMetadata::normalizeDeviceType($deviceType),
            'licenseId' => DeviceMetadata::normalizeLicenseId($licenseId),
            'simNumber' => $simNumber,
            'deviceId' => $deviceId,
            'company' => trim($company),
        ];
        $this->redis->pipeline(function ($pipe) use ($imei, $payload): void {
            $pipe->sadd($this->key('devices'), $imei);
            $pipe->hmset($this->deviceKey($imei), $payload);
        });
    }

    public function deleteDevice(string $imei): void
    {
        foreach ($this->redis->lrange($this->deviceListKey($imei, 'commands'), 0, $this->limit - 1) as $id) {
            $this->redis->hdel($this->commandIndexKey(), (string)$id);
        }

        $this->redis->srem($this->key('devices'), $imei);
        $this->redis->zrem($this->onlineDeviceSetKey(), $imei);
        $this->redis->del([
            $this->deviceKey($imei),
            $this->deviceListKey($imei, 'raw'),
            $this->deviceListKey($imei, 'telemetry'),
            $this->deviceListKey($imei, 'events'),
            $this->deviceListKey($imei, 'commands'),
            $this->commandHashKey($imei),
        ]);
    }

    public function updateDeviceAssociation(string $imei, string $company, int $licenseId): void
    {
        $payload = [
            'imei' => $imei,
            'company' => trim($company),
            'licenseId' => DeviceMetadata::normalizeLicenseId($licenseId),
        ];
        $this->redis->pipeline(function ($pipe) use ($imei, $payload): void {
            $pipe->sadd($this->key('devices'), $imei);
            $pipe->hmset($this->deviceKey($imei), $payload);
        });
    }

    public function deviceSeen(string $imei, array $fields): void
    {
        $now = gmdate('Y-m-d\\TH:i:s\\Z');
        $payload = array_merge($fields, [
            'imei' => $imei,
            'lastSeenAt' => $now,
        ]);
        $score = time();
        $this->redis->pipeline(function ($pipe) use ($imei, $payload, $score): void {
            $pipe->sadd($this->key('devices'), $imei);
            $pipe->hmset($this->deviceKey($imei), $payload);
            $pipe->zadd($this->onlineDeviceSetKey(), [$imei => $score]);
        });
    }

    public function deviceOffline(string $imei): void
    {
        $this->redis->hmset($this->deviceKey($imei), [
            'imei' => $imei,
            'online' => '0',
            'lastStateAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
        $this->redis->zrem($this->onlineDeviceSetKey(), $imei);
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

    /**
     * @param list<string> $imeis
     * @return array<string, array<string, mixed>>
     */
    public function runtimeStates(array $imeis): array
    {
        $states = [];
        foreach (array_values(array_unique(array_filter(array_map('strval', $imeis), static fn (string $imei): bool => $imei !== ''))) as $imei) {
            $state = $this->redis->hgetall($this->deviceKey($imei));
            if ($state === []) {
                continue;
            }
            $states[$imei] = $this->normalizeDevice($state);
        }

        return $states;
    }

    public function expireStaleDevices(int $timeoutSeconds): void
    {
        $cutoff = time() - max(1, $timeoutSeconds);
        foreach ($this->redis->zrangebyscore($this->onlineDeviceSetKey(), '-inf', (string)$cutoff) as $imei) {
            $this->deviceOffline((string)$imei);
        }
    }

    private function normalizeDevice(array $data): array
    {
        $data['online'] = ((string)($data['online'] ?? '0')) === '1';
        $data['deviceType'] = DeviceMetadata::normalizeDeviceType((string)($data['deviceType'] ?? 'watch'));
        $data['licenseId'] = DeviceMetadata::normalizeLicenseId((string)($data['licenseId'] ?? '0'));
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

    private function commandIndexKey(): string
    {
        return $this->key('command-index');
    }

    private function onlineDeviceSetKey(): string
    {
        return $this->key('online-devices-by-last-seen');
    }
}
