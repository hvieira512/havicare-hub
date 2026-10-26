<?php

declare(strict_types=1);

namespace Hub\State;

use Hub\Domain\DeviceMetadata;
use Hub\Domain\Liveness;
use Hub\Support\Values;
use Predis\ClientInterface;

final class DeviceRuntimeStore
{
    public function __construct(
        private ClientInterface $redis,
        private int $limit = 100,
    ) {
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
            $pipe->sadd(RedisKeys::devices(), $imei);
            $pipe->hmset(RedisKeys::device($imei), $payload);
        });
    }

    public function deleteDevice(string $imei): void
    {
        foreach ($this->redis->lrange(RedisKeys::list($imei, 'commands'), 0, $this->limit - 1) as $id) {
            $this->redis->hdel(RedisKeys::commandIndex(), [(string)$id]);
        }

        $this->redis->srem(RedisKeys::devices(), $imei);
        $this->redis->zrem(RedisKeys::onlineDevices(), $imei);
        $keys = [RedisKeys::device($imei), RedisKeys::commandRecords($imei), RedisKeys::sighting($imei), RedisKeys::activeAlarms($imei)];
        foreach (['raw', 'telemetry', 'events', 'commands', 'connections'] as $list) {
            $list = RedisKeys::list($imei, $list);
            array_push($keys, $list, "{$list}:latest", "{$list}:seq");
        }
        $this->redis->del($keys);
    }

    public function updateDeviceAssociation(string $imei, string $company, int $licenseId): void
    {
        $payload = [
            'imei' => $imei,
            'company' => DeviceMetadata::normalizeCompany($company),
            'licenseId' => DeviceMetadata::normalizeLicenseId($licenseId),
        ];
        $this->redis->pipeline(function ($pipe) use ($imei, $payload): void {
            $pipe->sadd(RedisKeys::devices(), $imei);
            $pipe->hmset(RedisKeys::device($imei), $payload);
        });
    }

    /** O que dura e já saiu volta a sair na próxima trama: um dono novo nunca o recebeu. */
    public function forgetActiveAlarms(string $imei): void
    {
        $this->redis->del([RedisKeys::activeAlarms($imei)]);
    }

    /**
     * Devolve se estava desligado: a transição lê-se do Redis, e não da memória de quem chama,
     * para um reinício do hub não passar por reconexão.
     *
     * @param array<string, mixed> $fields
     */
    public function deviceSeen(string $imei, array $fields): bool
    {
        $wasOnline = $this->redis->hget(RedisKeys::device($imei), 'online') === '1';
        $now = gmdate('Y-m-d\\TH:i:s\\Z');
        $payload = array_merge($fields, [
            'imei' => $imei,
            'lastSeenAt' => $now,
        ]);
        $score = time();
        $this->redis->pipeline(function ($pipe) use ($imei, $payload, $score): void {
            $pipe->sadd(RedisKeys::devices(), $imei);
            $pipe->hmset(RedisKeys::device($imei), $payload);
            $pipe->zadd(RedisKeys::onlineDevices(), [$imei => $score]);
        });

        return !$wasOnline;
    }

    /**
     * Adormece ou acorda quem está ligado, sem mexer no prazo dele.
     *
     * @return bool se mudou
     */
    public function deviceAsleep(string $imei, bool $asleep): bool
    {
        $state = $this->redis->hmget(RedisKeys::device($imei), ['online', 'asleep']);
        if (($state[0] ?? null) !== '1' || (($state[1] ?? null) === '1') === $asleep) {
            return false;
        }
        $this->redis->hset(RedisKeys::device($imei), 'asleep', $asleep ? '1' : '0');

        return true;
    }

    /**
     * Os que se alcançam agora: ligados e acordados, a medir incluídos.
     *
     * @return list<string>
     */
    public function reachableDeviceImeis(): array
    {
        $candidates = $this->onlineDeviceImeis();

        return array_values(array_filter(
            $candidates,
            fn (string $imei): bool => $this->redis->hget(RedisKeys::device($imei), 'asleep') !== '1',
        ));
    }

    /** Uma trama de quem já está ligado prova-o vivo, sem o voltar a ligar. */
    public function deviceHeard(string $imei): void
    {
        if ($this->redis->hget(RedisKeys::device($imei), 'online') !== '1') {
            return;
        }
        $this->redis->hset(RedisKeys::device($imei), 'lastSeenAt', gmdate('Y-m-d\\TH:i:s\\Z'));
        $this->redis->zadd(RedisKeys::onlineDevices(), [$imei => time()]);
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

        $this->redis->hset(RedisKeys::sighting($deviceKey), $gatewayKey, json_encode(
            Values::withoutNulls(['rssiDbm' => $rssiDbm, 'lastSeenAt' => gmdate('Y-m-d\\TH:i:s\\Z')]),
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * O `data` do desligado de um aparelho retransmitido: o último gateway que o ouviu, e se esse
     * gateway já está desligado ou se calou com ele.
     *
     * @param array<string, mixed> $device como `device()` o devolve
     * @return array<string, string>
     */
    public function relayedDisconnection(array $device): array
    {
        $gatewayId = (string)($device['gatewayId'] ?? '');
        if ($gatewayId === '') {
            return [];
        }
        $gateway = $this->device($gatewayId);
        $deviceSeenAt = strtotime((string)($device['lastSeenAt'] ?? ''));
        $gatewaySeenAt = strtotime((string)($gateway['lastSeenAt'] ?? ''));
        // A última trama do gateway trouxe o aparelho: os dois ficam com a mesma hora, a uns segundos.
        $silentTogether = $deviceSeenAt !== false && $gatewaySeenAt !== false && $gatewaySeenAt <= $deviceSeenAt + 5;

        return ['gatewayId' => $gatewayId] + ($gateway['online'] && !$silentTogether ? [] : ['cause' => 'gateway_offline']);
    }

    /** @return array<string, array<string, mixed>> chave do gateway => avistamento */
    public function gatewaySightings(string $deviceKey): array
    {
        $sightings = [];
        foreach ($this->redis->hgetall(RedisKeys::sighting($deviceKey)) ?: [] as $gatewayKey => $encoded) {
            $decoded = json_decode((string)$encoded, true);
            if (is_array($decoded)) {
                $sightings[(string)$gatewayKey] = $decoded;
            }
        }
        return $sightings;
    }

    public function recordProximityAlarmCandidate(string $deviceKey, string $gatewayKey): void
    {
        $this->redis->hset(RedisKeys::proximityAlarmCandidate($deviceKey), $gatewayKey, gmdate('Y-m-d\\TH:i:s\\Z'));
    }

    /** @return array<string, string> chave do gateway => instante ISO */
    public function proximityAlarmCandidates(string $deviceKey): array
    {
        return array_map('strval', $this->redis->hgetall(RedisKeys::proximityAlarmCandidate($deviceKey)) ?: []);
    }

    /** Devolve se estava ligado. */
    public function deviceOffline(string $imei): bool
    {
        $wasOnline = $this->redis->hget(RedisKeys::device($imei), 'online') === '1';
        $this->redis->hmset(RedisKeys::device($imei), [
            'imei' => $imei,
            'online' => '0',
            'asleep' => '0',
            'lastStateAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
        $this->redis->zrem(RedisKeys::onlineDevices(), $imei);

        return $wasOnline;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function devices(): array
    {
        $states = $this->runtimeStates(array_map('strval', $this->redis->smembers(RedisKeys::devices())));
        $devices = array_values($states);
        usort($devices, static fn (array $a, array $b): int => strcmp((string)($a['imei'] ?? ''), (string)($b['imei'] ?? '')));
        return $devices;
    }

    /** @return array<string, mixed> */
    public function device(string $imei): array
    {
        return $this->normalizeDevice($this->redis->hgetall(RedisKeys::device($imei)) ?: ['imei' => $imei]);
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
                $pipe->hgetall(RedisKeys::device($imei));
            }
        });

        $states = [];
        foreach ($imeis as $index => $imei) {
            // Os clientes leves em memória que alguns consumidores usam podem não expor os
            // resultados do pipeline, daí manter o caminho de leitura directa.
            $state = is_array($responses) && array_key_exists($index, $responses)
                ? $responses[$index]
                : $this->redis->hgetall(RedisKeys::device($imei));
            if ($state === []) {
                continue;
            }
            $states[$imei] = $this->normalizeDevice($state);
        }

        return $states;
    }

    /**
     * Dá por desligado quem se calou para lá do que o protocolo dele aguenta (`Liveness`), só do
     * tipo pedido quando há um.
     *
     * @param (\Closure(string): bool)|null $hasSession diz quem tem ligação própria, que decide por si
     * @return list<string> os que estavam ligados e deixaram de estar
     */
    public function expireStaleDevices(?string $deviceType = null, ?\Closure $hasSession = null): array
    {
        $now = time();
        /** @var array<string, string|float> $lastSeen */
        $lastSeen = $this->redis->zrangebyscore(RedisKeys::onlineDevices(), '-inf', '+inf', ['withscores' => true]);
        $states = $lastSeen !== [] ? $this->runtimeStates(array_map('strval', array_keys($lastSeen))) : [];
        $stale = [];
        foreach ($lastSeen as $imei => $seenAt) {
            $imei = (string)$imei;
            $state = $states[$imei] ?? [];
            if ($deviceType !== null && ($state['deviceType'] ?? null) !== $deviceType) {
                continue;
            }
            if ($hasSession !== null && $hasSession($imei)) {
                continue;
            }
            $timeout = Liveness::idleTimeout((string)($state['protocol'] ?? ''));
            if ($timeout !== null && (float)$seenAt <= $now - $timeout) {
                $stale[] = $imei;
            }
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
            $this->redis->zrangebyscore(RedisKeys::onlineDevices(), '-inf', '+inf')
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
}
