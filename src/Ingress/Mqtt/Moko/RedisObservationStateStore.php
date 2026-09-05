<?php

namespace Hub\Ingress\Mqtt\Moko;

use Predis\ClientInterface;

final class RedisObservationStateStore implements ObservationStateStore
{
    /** O estado da condição persiste enquanto o dispositivo reportar; um dia de folga chega. */
    private const CONDITION_TTL_SECONDS = 86400;

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
        // A chave só é consultada dentro da janela de refrescamento; o prazo é várias vezes essa
        // janela, para expirar bem depois de deixar de ser útil e não virar telemetria repetida.
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
}
