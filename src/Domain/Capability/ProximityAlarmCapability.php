<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

use Hub\Domain\ProximityAlarm;

/**
 * O alarme de proximidade de um gateway, sem downlink: as regras estão no `ProximityAlarm`. A chave
 * não é a do evento, `proximity_alarm`, porque uma chave do catálogo não é configuração num aparelho
 * e acontecimento noutro.
 */
final class ProximityAlarmCapability implements CapabilityContract, CapabilityInputSanitizer, HubAppliedCapability
{
    use CapabilityHelpers;

    public function key(): string
    {
        return 'proximity_alert';
    }

    public function isList(): bool
    {
        return false;
    }

    public function supportsMultipleNativeKeys(): bool
    {
        return false;
    }

    public function supportedProtocols(): array
    {
        return ['moko-mkgw3', 'moko-mkgw4'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        if (!in_array($protocol, $this->supportedProtocols(), true)) {
            throw new \InvalidArgumentException("Unsupported protocol {$protocol} for proximity_alert");
        }

        throw new \InvalidArgumentException('proximity_alert is applied by the hub and has no native command');
    }

    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        return $desired + ProximityAlarm::defaults();
    }

    public function defaultValue(string $protocol): mixed
    {
        return ProximityAlarm::defaults();
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return array_replace_recursive([
            'modes' => ProximityAlarm::MODES,
            'defaults' => ProximityAlarm::defaults(),
            'bounds' => [
                'triggerDbm' => ProximityAlarm::TRIGGER_DBM_BOUNDS,
                'minSamples' => ProximityAlarm::MIN_SAMPLES_BOUNDS,
                'cooldownSeconds' => ProximityAlarm::COOLDOWN_SECONDS_BOUNDS,
            ],
        ], $accumulatedMeta);
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return is_array($incoming) ? $incoming : $existing;
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return [
            'value' => (is_array($value) ? $value : []) + ProximityAlarm::defaults(),
            '_meta' => $this->meta($protocol, $meta),
        ];
    }

    public function sanitizeInput(string $protocol, mixed $value): mixed
    {
        return ProximityAlarm::sanitize($value);
    }
}
