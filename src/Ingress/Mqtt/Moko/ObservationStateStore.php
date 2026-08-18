<?php

namespace Hub\Ingress\Mqtt\Moko;

interface ObservationStateStore
{
    public function acceptObservation(string $deviceKey, string $fingerprint, int $ttlSeconds): bool;

    /**
     * `$observedBy` scopes the throttle to whoever made the observation. A relayed
     * BLE device is seen by every gateway in range, and each sighting is a distinct
     * measurement -- notably its RSSI, which differs per gateway. Without the scope
     * the first gateway to publish suppresses the others for `$refreshSeconds` and
     * which one wins is a race, so the reported gatewayId was effectively arbitrary.
     * Empty for a device reporting about itself.
     *
     * @param array<string, mixed> $payload
     */
    public function shouldPublish(string $deviceKey, string $capability, array $payload, int $refreshSeconds, string $observedBy = ''): bool;

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
