<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * A whitelist de chamadas.
 *
 * Forma pública:
 * - GET /api/devices/{imei}: o Vivistar devolve contactos como `[{name, phone}]`, o 4P Touch
 *   devolve uma lista de números.
 * - PATCH /api/devices/{imei}/configurations:
 *   - o Vivistar aceita `{contacts:[{name, phone}]}`
 *   - o 4P Touch aceita uma lista simples de números
 *
 * O interruptor que a liga e desliga é exposto à parte, como `whitelist_enabled`.
 */
final class CallWhitelistCapability implements CapabilityContract
{
    use CapabilityHelpers;

    private FourPTouchCallWhitelistHandler $fourPTouch;

    public function __construct(?FourPTouchCallWhitelistHandler $fourPTouch = null)
    {
        $this->fourPTouch = $fourPTouch ?? new FourPTouchCallWhitelistHandler();
    }

    public function key(): string
    {
        return 'call_whitelist';
    }

    public function isList(): bool
    {
        return false;
    }

    public function supportsMultipleNativeKeys(): bool
    {
        return true;
    }

    public function supportedProtocols(): array
    {
        return ['vivistar-iw', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        return match ($protocol) {
            // O payload nativo guardado fica estruturado: quem serializa os contactos como
            // `UTF-16BE(nome)|telefone` para o BP14 é só o construtor de payloads Vivistar.
            'vivistar-iw' => ['call_whitelist' => ['contacts' => self::normalizeContactsList($value)]],
            'four-p-touch' => $this->fourPTouch->toNative($value),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for call_whitelist"),
        };
    }

    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        if ($nativeKey === 'call_whitelist') {
            if (isset($desired['fields']) && is_array($desired['fields'])) {
                $contacts = [];
                foreach ($desired['fields'] as $field) {
                    $field = trim((string)$field);
                    if ($field === '') {
                        continue;
                    }
                    $separator = strpos($field, '|');
                    $name = $separator !== false ? substr($field, 0, $separator) : '';
                    $phone = $separator !== false ? substr($field, $separator + 1) : $field;
                    $name = trim((string)$name);
                    $phone = trim((string)$phone);
                    if ($phone !== '') {
                        $contacts[] = [
                            'name' => $name,
                            'phone' => $phone,
                        ];
                    }
                }

                return self::normalizeContactsList($contacts);
            }

            if (isset($desired['contacts']) && is_array($desired['contacts'])) {
                return self::normalizeContactsList($desired['contacts']);
            }

            if (isset($desired['numbers']) && is_array($desired['numbers'])) {
                return self::normalizeContactsList(array_map(
                    static fn(mixed $phone): array => ['name' => '', 'phone' => $phone],
                    $desired['numbers']
                ));
            }

            if (array_is_list($desired)) {
                return self::normalizeContactsList($desired);
            }
        }

        if ($nativeKey === 'whitelistGroup1' || $nativeKey === 'whitelistGroup2') {
            return $this->fourPTouch->fromNative($desired);
        }

        return [];
    }

    public function defaultValue(string $protocol): mixed
    {
        return match ($protocol) {
            'vivistar-iw' => [['name' => '', 'phone' => '']],
            'four-p-touch' => $this->fourPTouch->defaultValue(),
            default => [],
        };
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        if ($protocol === 'four-p-touch') {
            return $this->fourPTouch->meta($accumulatedMeta);
        }

        return array_replace_recursive(
            ['limit' => 10, 'phone' => ['asciiOnly' => true]],
            $accumulatedMeta,
        );
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeListValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        if ($protocol === 'four-p-touch') {
            return $this->fourPTouch->responseEntry($protocol, $nativeKey, $value, $meta);
        }

        return [
            'value' => self::normalizeContactsList($value),
            '_meta' => $this->meta($protocol, $meta),
        ];
    }


    /**
     * @return list<array{name: string, phone: string}>
     */
    private static function normalizeContactsList(mixed $value): array
    {
        $items = [];
        if (is_array($value)) {
            if (isset($value['contacts']) && is_array($value['contacts'])) {
                $items = $value['contacts'];
            } elseif (isset($value['numbers']) && is_array($value['numbers'])) {
                $items = $value['numbers'];
            } elseif (array_is_list($value)) {
                $items = $value;
            }
        }

        $contacts = [];
        $seenPhones = [];
        foreach ($items as $item) {
            $contact = self::normalizeContactItem($item);
            if ($contact === null) {
                continue;
            }
            if (in_array($contact['phone'], $seenPhones, true)) {
                throw new \InvalidArgumentException('contacts must not contain repeated phone values');
            }
            $seenPhones[] = $contact['phone'];
            $contacts[] = $contact;
        }

        return $contacts;
    }

    /**
     * @return array{name: string, phone: string}|null
     */
    private static function normalizeContactItem(mixed $item): ?array
    {
        if (is_array($item)) {
            $name = trim((string)($item['name'] ?? ''));
            $phone = trim((string)($item['phone'] ?? ''));
            if ($name === '' && $phone === '') {
                return null;
            }
            if ($phone === '') {
                throw new \InvalidArgumentException('phone is required');
            }

            return ['name' => $name, 'phone' => $phone];
        }

        $field = trim((string)$item);
        if ($field === '') {
            return null;
        }

        $separator = strpos($field, '|');
        $name = $separator !== false ? trim((string)substr($field, 0, $separator)) : '';
        $phone = $separator !== false ? trim((string)substr($field, $separator + 1)) : $field;
        if ($phone === '') {
            return null;
        }

        return ['name' => $name, 'phone' => $phone];
    }
}
