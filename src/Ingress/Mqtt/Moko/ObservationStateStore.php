<?php

namespace Hub\Ingress\Mqtt\Moko;

interface ObservationStateStore
{
    public function acceptObservation(string $deviceKey, string $fingerprint, int $ttlSeconds): bool;

    /** @param array<string, mixed> $payload */
    public function shouldPublish(string $deviceKey, string $capability, array $payload, int $refreshSeconds): bool;

    /** Returns null while establishing the initial state. */
    public function transitionCondition(string $deviceKey, string $condition): ?string;
}
