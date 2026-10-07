<?php

declare(strict_types=1);

namespace Hub\State;

use Hub\Domain\DeviceMetadata;
use Hub\Support\Values;
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
        int|string $licenseId = 0,
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
            'company' => DeviceMetadata::normalizeCompany($company),
        ];
        $this->redis->pipeline(function ($pipe) use ($imei, $payload): void {
            $pipe->sadd($this->key('devices'), $imei);
            $pipe->hmset($this->deviceKey($imei), $payload);
        });
    }

    public function deleteDevice(string $imei): void
    {
        foreach ($this->redis->lrange($this->deviceListKey($imei, 'commands'), 0, $this->limit - 1) as $id) {
            $this->redis->hdel($this->commandIndexKey(), [(string)$id]);
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
            $this->sightingKey($imei),
        ]);
    }

    public function updateDeviceAssociation(string $imei, string $company, int $licenseId): void
    {
        $payload = [
            'imei' => $imei,
            'company' => DeviceMetadata::normalizeCompany($company),
            'licenseId' => DeviceMetadata::normalizeLicenseId($licenseId),
        ];
        $this->redis->pipeline(function ($pipe) use ($imei, $payload): void {
            $pipe->sadd($this->key('devices'), $imei);
            $pipe->hmset($this->deviceKey($imei), $payload);
        });
    }

    /**
     * Devolve se estava desligado: a transição lê-se do Redis, e não da memória de quem chama,
     * para um reinício do hub não passar por reconexão.
     *
     * @param array<string, mixed> $fields
     */
    public function deviceSeen(string $imei, array $fields): bool
    {
        $wasOnline = $this->redis->hget($this->deviceKey($imei), 'online') === '1';
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

        return !$wasOnline;
    }

    /**
     * A última vez que um gateway ouviu um dispositivo retransmitido, e com que força. O sinal é
     * do par, e por isso fica indexado pelo dispositivo e fora do hash dele.
     */
    public function recordGatewaySighting(string $deviceKey, string $gatewayKey, ?int $rssiDbm): void
    {
        if ($deviceKey === '' || $gatewayKey === '') {
            return;
        }

        $this->redis->hset($this->sightingKey($deviceKey), $gatewayKey, json_encode(
            Values::withoutNulls(['rssiDbm' => $rssiDbm, 'lastSeenAt' => gmdate('Y-m-d\\TH:i:s\\Z')]),
            JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string, array<string, mixed>> chave do gateway => avistamento */
    public function gatewaySightings(string $deviceKey): array
    {
        $sightings = [];
        foreach ($this->redis->hgetall($this->sightingKey($deviceKey)) ?: [] as $gatewayKey => $encoded) {
            $decoded = json_decode((string)$encoded, true);
            if (is_array($decoded)) {
                $sightings[(string)$gatewayKey] = $decoded;
            }
        }
        return $sightings;
    }

    /** Devolve se estava ligado. */
    public function deviceOffline(string $imei): bool
    {
        $wasOnline = $this->redis->hget($this->deviceKey($imei), 'online') === '1';
        $this->redis->hmset($this->deviceKey($imei), [
            'imei' => $imei,
            'online' => '0',
            'lastStateAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
        $this->redis->zrem($this->onlineDeviceSetKey(), $imei);

        return $wasOnline;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function devices(): array
    {
        $states = $this->runtimeStates(array_map('strval', $this->redis->smembers($this->key('devices'))));
        $devices = array_values($states);
        usort($devices, static fn (array $a, array $b): int => strcmp((string)($a['imei'] ?? ''), (string)($b['imei'] ?? '')));
        return $devices;
    }

    /** @return array<string, mixed> */
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
        $imeis = array_values(array_unique(array_filter(
            array_map('strval', $imeis),
            static fn (string $imei): bool => $imei !== ''
        )));
        if ($imeis === []) {
            return [];
        }

        $responses = $this->redis->pipeline(function ($pipe) use ($imeis): void {
            foreach ($imeis as $imei) {
                $pipe->hgetall($this->deviceKey($imei));
            }
        });

        $states = [];
        foreach ($imeis as $index => $imei) {
            // Os clientes leves em memória que alguns consumidores usam podem não expor os
            // resultados do pipeline, daí manter o caminho de leitura directa.
            $state = is_array($responses) && array_key_exists($index, $responses)
                ? $responses[$index]
                : $this->redis->hgetall($this->deviceKey($imei));
            if ($state === []) {
                continue;
            }
            $states[$imei] = $this->normalizeDevice($state);
        }

        return $states;
    }

    /**
     * Dá por desligado quem está calado há mais do que o prazo, só do tipo pedido quando há um.
     *
     * @return list<string> os que estavam ligados e deixaram de estar
     */
    public function expireStaleDevices(int $timeoutSeconds, ?string $deviceType = null): array
    {
        $cutoff = time() - max(1, $timeoutSeconds);
        $stale = array_map('strval', $this->redis->zrangebyscore($this->onlineDeviceSetKey(), '-inf', (string)$cutoff));
        if ($deviceType !== null && $stale !== []) {
            $states = $this->runtimeStates($stale);
            $stale = array_values(array_filter(
                $stale,
                static fn(string $imei): bool => ($states[$imei]['deviceType'] ?? null) === $deviceType,
            ));
        }

        return array_values(array_filter($stale, fn(string $imei): bool => $this->deviceOffline($imei)));
    }

    /**
     * Os dispositivos ligados: candidatos do conjunto ordenado por última vez visto, decididos pela
     * bandeira de estado que a pastilha do painel lê, para nunca discordarem.
     *
     * @return list<string>
     */
    public function onlineDeviceImeis(): array
    {
        $candidates = array_values(array_filter(array_map(
            'strval',
            $this->redis->zrangebyscore($this->onlineDeviceSetKey(), '-inf', '+inf')
        )));
        if ($candidates === []) {
            return [];
        }

        $online = [];
        foreach ($this->runtimeStates($candidates) as $imei => $state) {
            if (($state['online'] ?? false) === true) {
                $online[] = (string)$imei;
            }
        }

        return $online;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
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

    private function sightingKey(string $imei): string
    {
        return $this->key("sighting:{$imei}");
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
