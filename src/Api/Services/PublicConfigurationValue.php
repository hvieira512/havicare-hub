<?php

namespace Hub\Api\Services;

use Hub\Domain\Capability\CapabilityHelpers;

/**
 * A forma pública de um valor de configuração — a mesma que sai no `configurations` e no
 * `configurationSync` de `/api/devices/{imei}`. Vivia copiada nos dois serviços; um deles
 * podia derivar do outro e discordar sobre se a whitelist estava sincronizada.
 *
 * A whitelist de chamadas depende do protocolo: o Vivistar leva contactos `{name, phone}`,
 * os outros protocolos comparam números simples.
 */
final class PublicConfigurationValue
{
    use CapabilityHelpers;

    public function forGenericKey(string $protocol, string $genericKey, mixed $value): mixed
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
}
