<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Ingress\Mqtt\Gateway\ObservationStateStore;

final class ArrayObservationStateStore implements ObservationStateStore
{
    /** @var array<string, int> */
    private array $observations = [];
    /** @var array<string, array{fingerprint: string, publishedAt: int}> */
    private array $published = [];
    /** @var array<string, string> */
    private array $conditions = [];

    public function acceptObservation(string $deviceKey, string $fingerprint, int $ttlSeconds): bool
    {
        $key = $deviceKey . ':' . $fingerprint;
        if (($this->observations[$key] ?? 0) > time()) {
            return false;
        }
        $this->observations[$key] = time() + max(1, $ttlSeconds);
        return true;
    }

    public function shouldPublish(string $deviceKey, string $capability, array $payload, int $refreshSeconds, string $observedBy = ''): bool
    {
        $key = $deviceKey . ':' . $capability . ':' . $observedBy;
        $fingerprint = hash('sha256', json_encode($payload['data'] ?? []) ?: '');
        $stored = $this->published[$key] ?? null;
        if (is_array($stored) && $stored['fingerprint'] === $fingerprint && time() - $stored['publishedAt'] < max(1, $refreshSeconds)) {
            return false;
        }
        $this->published[$key] = ['fingerprint' => $fingerprint, 'publishedAt' => time()];
        return true;
    }

    /** @return array{previous: ?string}|null */
    public function transitionCondition(string $deviceKey, string $condition): ?array
    {
        $previous = $this->conditions[$deviceKey] ?? null;
        $this->conditions[$deviceKey] = $condition;
        $known = is_string($previous) && $previous !== '';
        if ($known && $previous === $condition) {
            return null;
        }
        return ['previous' => $known ? $previous : null];
    }
}
