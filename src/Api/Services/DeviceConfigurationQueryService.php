<?php

namespace Hub\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityHelpers;
use Hub\Domain\Capability\CapabilityRegistry;

final class DeviceConfigurationQueryService
{
    use CapabilityHelpers;

    public function __construct(
        private ApiDataAccess $db,
        private CapabilityRegistry $capabilities,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function current(string $imei, string $protocol): array
    {
        $configurations = [];
        foreach ($this->db->deviceConfigurations->allForImei($imei) as $row) {
            $desired = $row['desired_payload'];
            if (!is_array($desired) || $desired === []) {
                continue;
            }

            $nativeKey = $this->storedNativeKey($row);
            $genericKey = CapabilityCatalog::normalizeStoredCapabilityKey(
                (string)($row['config_key'] ?? $nativeKey ?? '')
            );
            if ($nativeKey === null || $genericKey === null) {
                continue;
            }

            $section = CapabilityCatalog::sectionForCapabilityKey($genericKey);
            if ($section === null || $section === 'telemetry') {
                continue;
            }

            $normalized = $this->publicValue(
                $protocol,
                $genericKey,
                $this->capabilities->fromNative($protocol, $genericKey, $nativeKey, $desired)
            );
            if ($normalized === null) {
                continue;
            }

            $supportsMultipleNativeKeys = $this->capabilities->has($genericKey)
                && $this->capabilities->get($genericKey)?->supportsMultipleNativeKeys();
            if (!array_key_exists($genericKey, $configurations) || !$supportsMultipleNativeKeys) {
                $configurations[$genericKey] = $normalized;
                continue;
            }

            $configurations[$genericKey] = $this->capabilities->merge(
                $genericKey,
                $configurations[$genericKey],
                $normalized
            );
        }

        return $configurations;
    }

    public function publicValue(string $protocol, string $genericKey, mixed $value): mixed
    {
        return match ($genericKey) {
            'sos_contacts' => is_array($value) ? $this->stringifyPhoneList($value) : [],
            'call_whitelist' => is_array($value) ? $this->stringifyCallWhitelist($protocol, $value) : $value,
            default => $value,
        };
    }

    /**
     * @param array<string|int, mixed> $value
     */

    /**
     * @param array<string|int, mixed> $value
     */
    private function stringifyCallWhitelist(string $protocol, array $value): mixed
    {
        $items = $value['contacts'] ?? $value['numbers'] ?? $value;
        if ($protocol === 'vivistar-iw' && is_array($items) && array_is_list($items)) {
            return array_values(array_filter(array_map(
                static fn(mixed $item): ?array => self::normalizeContact($item),
                $items
            )));
        }

        if (array_key_exists('numbers', $value) && is_array($value['numbers'])) {
            return self::stringList($value['numbers']);
        }
        if (array_key_exists('contacts', $value) && is_array($value['contacts'])) {
            return self::stringList(array_map(
                static fn(mixed $contact): string => self::contactPhone($contact),
                $value['contacts']
            ));
        }
        if (!array_is_list($value)) {
            return array_key_exists('phone', $value)
                ? self::stringList([(string)$value['phone']])
                : $value;
        }
        if ($value !== [] && is_array($value[0] ?? null)) {
            return self::stringList(array_map(
                static fn(mixed $contact): string => self::contactPhone($contact),
                $value
            ));
        }

        return self::stringList($value);
    }

    /**
     * @return array{name: string, phone: string}|null
     */
    private static function normalizeContact(mixed $item): ?array
    {
        if (!is_array($item)) {
            $phone = trim((string)$item);
            return $phone === '' ? null : ['name' => '', 'phone' => $phone];
        }

        $name = trim((string)($item['name'] ?? ''));
        $phone = trim((string)($item['phone'] ?? ''));
        return $phone === '' ? null : ['name' => $name, 'phone' => $phone];
    }

    private static function contactPhone(mixed $item): string
    {
        return trim((string)(is_array($item) ? ($item['phone'] ?? '') : $item));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function storedNativeKey(array $row): ?string
    {
        $key = trim((string)($row['native_key'] ?? $row['config_key'] ?? ''));
        return $key === '' ? null : $key;
    }
}
