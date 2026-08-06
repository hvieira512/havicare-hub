<?php

namespace Hub\Ingress\Mqtt\Moko;

final class ArrayObservationStateStore implements ObservationStateStore
{
    private array $observations = [];
    private array $published = [];
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

    public function shouldPublish(string $deviceKey, string $capability, array $payload, int $refreshSeconds): bool
    {
        $key = $deviceKey . ':' . $capability;
        $fingerprint = hash('sha256', json_encode($payload['data'] ?? []) ?: '');
        $stored = $this->published[$key] ?? null;
        if (is_array($stored) && $stored['fingerprint'] === $fingerprint && time() - $stored['publishedAt'] < max(1, $refreshSeconds)) {
            return false;
        }
        $this->published[$key] = ['fingerprint' => $fingerprint, 'publishedAt' => time()];
        return true;
    }

    public function transitionCondition(string $deviceKey, string $condition): ?string
    {
        $previous = $this->conditions[$deviceKey] ?? null;
        $this->conditions[$deviceKey] = $condition;
        return is_string($previous) && $previous !== $condition ? $previous : null;
    }
}
