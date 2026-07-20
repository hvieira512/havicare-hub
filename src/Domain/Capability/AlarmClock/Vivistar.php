<?php

namespace Hub\Domain\Capability\AlarmClock;

/**
 * Vivistar / Wonlex alarm_clock handler.
 *
 * Native key: 'reminders'
 * Native shape: { reminders: { masterEnabled: bool, items: [{time, days, enabled, type}] } }
 */
final class Vivistar implements AlarmClockHandler
{
    use AlarmClockHelpers;

    public function nativeKey(): string
    {
        return 'reminders';
    }

    public function toNative(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return ['reminders' => [
                'masterEnabled' => true,
                'items' => array_map(
                    static fn(mixed $item): array => self::normalizeItemForNative($item),
                    $value,
                ),
            ]];
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('reminders must be an object');
        }

        $payload = $value;
        $payload['masterEnabled'] = $payload['masterEnabled'] ?? true;

        $items = $payload['items'] ?? [];
        if (!is_array($items) || !array_is_list($items)) {
            throw new \InvalidArgumentException('items must be an array');
        }
        $payload['items'] = array_map(
            static fn(mixed $item): array => self::normalizeItemForNative($item),
            $items,
        );

        return ['reminders' => $payload];
    }

    public function fromNative(array $desired): array
    {
        $items = $desired['items'] ?? $desired['alarms'] ?? $desired;
        if (!is_array($items)) {
            return [];
        }
        if (!array_is_list($items)) {
            $items = [$items];
        }
        if ($items !== [] && is_array($items[0] ?? null) && array_key_exists('recurrence', $items[0])) {
            return array_values($items);
        }

        return array_values(array_filter(
            array_map(static fn(mixed $item): array => self::publicItem($item), $items),
            static fn(array $item): bool => $item !== [],
        ));
    }

    public function defaultValue(): mixed
    {
        return [
            'items' => [
                [
                    'time' => '',
                    'enabled' => true,
                    'type' => 1,
                    'recurrence' => ['kind' => 'once'],
                ],
            ],
        ];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        $meta = $accumulatedMeta;

        $recurrenceOptions = $meta['mode']['options'] ?? null;
        if (!is_array($recurrenceOptions) || $recurrenceOptions === []) {
            $recurrenceOptions = [
                ['value' => 'once', 'label' => 'Uma vez'],
                ['value' => 'daily', 'label' => 'Todos os dias'],
                ['value' => 'custom', 'label' => 'Personalizado'],
            ];
        }

        $meta['recurrence'] = ['options' => $recurrenceOptions];

        return $meta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        $existingList = is_array($existing) ? array_values($existing) : [];
        $incomingList = is_array($incoming) ? array_values($incoming) : [];

        return array_values(array_merge($existingList, $incomingList));
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return [
            'value' => $this->fromNative(is_array($value) ? $value : []),
            '_meta' => $this->meta($meta),
        ];
    }

    // ------------------------------------------------------------------
    // Normalization
    // ------------------------------------------------------------------

    private static function normalizeItemForNative(mixed $item): array
    {
        if (!is_array($item)) {
            return ['time' => '', 'days' => '', 'enabled' => true, 'type' => 1];
        }

        $days = self::resolveDaysForNative($item);

        if (!array_key_exists('type', $item) || $item['type'] === null || trim((string)$item['type']) === '') {
            throw new \InvalidArgumentException('type is required');
        }

        $type = (int)$item['type'];
        if ($type < 1 || $type > 3) {
            throw new \InvalidArgumentException('type must be between 1 and 3');
        }

        return [
            'time' => (string)($item['time'] ?? ''),
            'days' => $days,
            'enabled' => self::boolLikeToBool($item['enabled'] ?? true),
            'type' => $type,
        ];
    }

    private static function resolveDaysForNative(array $item): string
    {
        $recurrence = is_array($item['recurrence'] ?? null) ? $item['recurrence'] : [];
        $kind = strtolower(trim((string)($recurrence['kind'] ?? '')));

        $daysValue = match ($kind) {
            'daily' => '',
            'custom', 'once' => $recurrence['days'] ?? $item['days'] ?? $item['custom'] ?? '',
            default => $item['days'] ?? $item['custom'] ?? '',
        };

        if ($kind === 'daily') {
            return '1234567';
        }

        if (is_array($daysValue)) {
            return self::formatDayList($daysValue);
        }

        return preg_replace('/[^0-9]/', '', (string)$daysValue);
    }

    public static function publicItem(mixed $item): array
    {
        if (!is_array($item)) {
            return [];
        }

        $days = self::parseDayList((string)($item['days'] ?? ''));
        $kind = $days === []
            ? 'once'
            : ($days === [1, 2, 3, 4, 5, 6, 7] ? 'daily' : 'custom');

        $payload = [
            'time' => (string)($item['time'] ?? ''),
            'enabled' => self::boolLikeToBool($item['enabled'] ?? true),
            'recurrence' => ['kind' => $kind],
        ];

        if (isset($item['type']) && is_numeric((string)$item['type'])) {
            $payload['type'] = (int)$item['type'];
        }

        if ($days !== []) {
            $payload['recurrence']['days'] = $days;
        }

        return $payload;
    }
}
