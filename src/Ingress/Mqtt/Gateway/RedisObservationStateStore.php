<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Gateway;

use Predis\ClientInterface;

final class RedisObservationStateStore implements ObservationStateStore
{
    /** O estado da condição persiste enquanto o dispositivo reportar; um dia de folga chega. */
    private const CONDITION_TTL_SECONDS = 86400;

    /**
     * Um contador até este valor, com o guardado mais do que isto acima, é um reinício; abaixo
     * disso é um gateway atrasado. Um atraso maior, ou um reinício visto já depois disto, perde-se.
     */
    public const COUNTER_RESET_MAX = 3;

    /** Ler, decidir e guardar num só passo, para dois gateways com o mesmo valor não publicarem os dois. */
    private const ADVANCE_COUNTER_SCRIPT = <<<'LUA'
        local stored = tonumber(redis.call('GET', KEYS[1]))
        local count, ttl, resetMax = tonumber(ARGV[1]), ARGV[2], tonumber(ARGV[3])
        if stored ~= nil and count == stored then
            redis.call('EXPIRE', KEYS[1], ttl)
            return nil
        end
        if stored ~= nil and count < stored and not (count <= resetMax and stored - count > resetMax) then
            return nil
        end
        redis.call('SET', KEYS[1], count, 'EX', ttl)
        if stored == nil then
            return nil
        end
        return count > stored and stored or 0
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

    public function advanceCounter(string $deviceKey, int $count): ?int
    {
        $previous = $this->redis->eval(
            self::ADVANCE_COUNTER_SCRIPT,
            1,
            "{$this->prefix}:condition:{$deviceKey}",
            (string)$count,
            (string)self::CONDITION_TTL_SECONDS,
            (string)self::COUNTER_RESET_MAX,
        );
        return $previous === null ? null : (int)$previous;
    }
}
