<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Capability for call whitelist.
 *
 * Maps to different native shapes per protocol:
 * - vivistar-iw: { whitelistSwitch: { enabled: bool } }
 * - four-p-touch: { whitelistGroup1: { numbers: [...] }, whitelistGroup2: { numbers: [...] } }
 */
final class CallWhitelistCapability implements CapabilityContract
{
    use CapabilityHelpers;

    public function key(): string
    {
        return 'call_whitelist';
    }

    public function section(): string
    {
        return 'contacts';
    }

    public function isList(): bool
    {
        return false;
    }

    public function supportedProtocols(): array
    {
        return ['vivistar-iw', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        return match ($protocol) {
            'vivistar-iw' => ['whitelistSwitch' => ['enabled' => self::requireBoolLikeField($value, 'enabled')]],
            'four-p-touch' => $this->fourPTouchSplit(
                self::requireStringListValue($value['numbers'] ?? [], 'numbers'),
            ),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for call_whitelist"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        if ($nativeKey === 'whitelistSwitch') {
            return ['enabled' => (bool)($desired['enabled'] ?? false)];
        }

        return ['numbers' => self::stringList($desired['numbers'] ?? [])];
    }

    public function defaultValue(string $protocol): mixed
    {
        return match ($protocol) {
            'vivistar-iw' => ['enabled' => true],
            'four-p-touch' => ['numbers' => ['', '', '', '', '']],
            default => ['enabled' => true],
        };
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return $accumulatedMeta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeAssociativeValues($existing, $incoming, ['numbers']);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return [
            'value' => $value,
            '_meta' => $meta,
            '_type' => $this->key(),
        ];
    }

    public function nativeKeyForProtocol(string $protocol): ?string
    {
        return match ($protocol) {
            'vivistar-iw' => 'whitelistSwitch',
            'four-p-touch' => null, // splits into whitelistGroup1/2
            default => null,
        };
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }

    // ------------------------------------------------------------------
    // 4P Touch splitting
    // ------------------------------------------------------------------

    /** @param list<string> $numbers */
    private function fourPTouchSplit(array $numbers): array
    {
        return [
            'whitelistGroup1' => ['numbers' => array_slice($numbers, 0, 5)],
            'whitelistGroup2' => ['numbers' => array_slice($numbers, 5, 5)],
        ];
    }
}
