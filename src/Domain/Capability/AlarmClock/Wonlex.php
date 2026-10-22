<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\AlarmClock;

use Hub\Domain\Capability\CapabilityProtocolHandler;

final class Wonlex implements CapabilityProtocolHandler
{
    public function nativeKey(): string
    {
        return 'alarmClock';
    }

    public function toNative(mixed $value): array
    {
        $items = is_array($value) && array_is_list($value)
            ? $value
            : (is_array($value) ? ($value['items'] ?? []) : null);
        if (!is_array($items) || !array_is_list($items)) {
            throw new \InvalidArgumentException('items must be an array');
        }

        return ['alarmClock' => ['items' => $items]];
    }

    /**
     * @param array<array-key, mixed> $desired
     * @return list<array<string, mixed>>
     */
    public function fromNative(array $desired): array
    {
        // O `?? $desired` no fim: o apresentador chama isto duas vezes na mesma leitura, e à
        // segunda o que chega já é a lista pública, sem `items` nem `alarmClockList` à volta.
        $items = $desired['items'] ?? $desired['alarmClockList'] ?? $desired;
        if (!is_array($items) || !array_is_list($items)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $item): array {
            if (!is_array($item)) {
                return [];
            }
            if (is_array($item['recurrence'] ?? null)) {
                return $item;
            }

            $days = Weekdays::days(trim((string)($item['week'] ?? '')), false);
            $recurrence = count($days) === 7
                ? ['kind' => 'daily']
                : ['kind' => 'custom', 'days' => $days];

            return array_filter([
                'label' => trim((string)($item['label'] ?? '')),
                'time' => trim((string)($item['startTime'] ?? $item['time'] ?? '')),
                'enabled' => isset($item['status'])
                    ? ((string)$item['status'] === '1')
                    : (bool)($item['enabled'] ?? true),
                'recurrence' => $recurrence,
                'url' => trim((string)($item['url'] ?? '')),
            ], static fn(mixed $field): bool => $field !== '');
        }, $items), static fn(array $item): bool => $item !== []));
    }

    public function defaultValue(): mixed
    {
        return ['items' => []];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return array_replace_recursive([
            'limit' => 10,
            'label' => ['supported' => true],
            'url' => [
                'supported' => true,
                'format' => 'uri',
                'schemes' => ['http', 'https'],
            ],
            'week' => [
                'order' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            ],
            'mode' => [
                'options' => [['value' => 'daily'], ['value' => 'custom']],
            ],
            'days' => [
                'options' => array_map(static fn(int $day): array => ['value' => $day], range(1, 7)),
            ],
        ], $accumulatedMeta);
    }
}
