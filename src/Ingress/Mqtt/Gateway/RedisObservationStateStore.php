<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Gateway;

use Predis\ClientInterface;

final class RedisObservationStateStore implements ObservationStateStore
{
    /** O estado da condição persiste enquanto o dispositivo reportar; um dia de folga chega. */
    private const CONDITION_TTL_SECONDS = 86400;

    /**
     * Ler, decidir e guardar num só passo, para dois gateways com o mesmo valor não publicarem os
     * dois. A marca é o maior valor visto; cada gateway guarda o último seu, porque só no mesmo
     * gateway uma descida é reinício: entre gateways é atraso.
     */
    private const ADVANCE_COUNTER_SCRIPT = <<<'LUA'
        local mark = tonumber(redis.call('GET', KEYS[1]))
        local last = tonumber(redis.call('HGET', KEYS[2], ARGV[1]))
        local count, ttl = tonumber(ARGV[2]), ARGV[3]
        local from = nil
        if mark == nil or count > mark then
            redis.call('SET', KEYS[1], count, 'EX', ttl)
            from = mark
        elseif count < mark and last ~= nil and count < last then
            redis.call('SET', KEYS[1], count, 'EX', ttl)
            redis.call('DEL', KEYS[2])
            from = 0
        else
            redis.call('EXPIRE', KEYS[1], ttl)
        end
        redis.call('HSET', KEYS[2], ARGV[1], count)
        redis.call('EXPIRE', KEYS[2], ttl)
        return from
        LUA;

    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'hub:moko',
    ) {
    }

    public function acceptObservation(string $deviceKey, string $fingerprint, int $ttlSeconds): bool
    {
        $result = $this->redis->set(
            "{$this->prefix}:dedupe:{$deviceKey}:{$fingerprint}",
            '1',
            'EX',
            max(1, $ttlSeconds),
            'NX'
        );
        return strtoupper((string)$result) === 'OK';
    }

    public function shouldPublish(string $deviceKey, string $capability, array $payload, int $refreshSeconds, string $observedBy = ''): bool
    {
        $key = "{$this->prefix}:last:{$deviceKey}:{$capability}" . ($observedBy === '' ? '' : ":{$observedBy}");
        $fingerprint = hash('sha256', json_encode($payload['data'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $now = time();
        $stored = json_decode((string)($this->redis->get($key) ?? ''), true);
        if (is_array($stored) && ($stored['fingerprint'] ?? '') === $fingerprint && $now - (int)($stored['publishedAt'] ?? 0) < max(1, $refreshSeconds)) {
            return false;
        }
        // O prazo é várias vezes a janela de refrescamento, para expirar bem depois de servir.
        $this->redis->set(
            $key,
            json_encode(['fingerprint' => $fingerprint, 'publishedAt' => $now], JSON_THROW_ON_ERROR),
            'EX',
            max($refreshSeconds * 4, 3600)
        );
        return true;
    }

    /** @return array{previous: ?string}|null */
    public function transitionCondition(string $deviceKey, string $condition): ?array
    {
        $key = "{$this->prefix}:condition:{$deviceKey}";
        $previous = $this->redis->get($key);
        $this->redis->set($key, $condition, 'EX', self::CONDITION_TTL_SECONDS);
        $known = is_string($previous) && $previous !== '';
        if ($known && $previous === $condition) {
            return null;
        }
        return ['previous' => $known ? $previous : null];
    }

    public function advanceCounter(string $deviceKey, string $observedBy, int $count): ?int
    {
        $key = "{$this->prefix}:condition:{$deviceKey}";
        $from = $this->redis->eval(
            self::ADVANCE_COUNTER_SCRIPT,
            2,
            $key,
            "{$key}:gateways",
            $observedBy,
            (string)$count,
            (string)self::CONDITION_TTL_SECONDS,
        );
        return $from === null ? null : (int)$from;
    }
}
