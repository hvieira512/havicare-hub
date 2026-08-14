<?php

namespace Hub\Ingress\Mqtt\Moko;

interface ObservationStateStore
{
    public function acceptObservation(string $deviceKey, string $fingerprint, int $ttlSeconds): bool;

    /** @param array<string, mixed> $payload */
    public function shouldPublish(string $deviceKey, string $capability, array $payload, int $refreshSeconds): bool;

    /**
     * Returns null when the condition did not change, otherwise the transition.
     *
     * `previous` is null on the first observation of a device, which is a transition
     * like any other: a sensor whose very first reading already needs attention has
     * changed from "unknown" to that state, and callers must be able to act on it.
     * Returning null for both cases -- as this did before -- silently swallowed the
     * alarm for a device seen for the first time, or seen for the first time after
     * the store lost its data.
     *
     * @return array{previous: ?string}|null
     */
    public function transitionCondition(string $deviceKey, string $condition): ?array;
}
