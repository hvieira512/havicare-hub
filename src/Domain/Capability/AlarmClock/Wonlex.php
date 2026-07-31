<?php

namespace Hub\Domain\Capability\AlarmClock;

final class Wonlex implements AlarmClockHandler
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

    public function fromNative(array $desired): array
    {
        $items = $desired['items'] ?? $desired['alarmClockList'] ?? [];
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

            $week = trim((string)($item['week'] ?? '0000000'));
            $days = [];
            if (preg_match('/^[01]{7}$/', $week)) {
                for ($index = 0; $index < 7; $index++) {
                    if ($week[$index] === '1') {
                        $days[] = $index + 1;
                    }
                }
            }
            $recurrence = count($days) === 7
                ? ['kind' => 'daily']
                : ($days === [] ? ['kind' => 'once'] : ['kind' => 'custom', 'days' => $days]);

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
            'label' => [
                'supported' => true,
                'label' => 'Nome do alarme',
            ],
            'url' => [
                'supported' => true,
                'label' => 'Áudio do lembrete',
                'format' => 'uri',
                'schemes' => ['http', 'https'],
            ],
            'week' => [
                'order' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                'onceBehavior' => 'current_weekday',
            ],
            'days' => [
                'options' => [
                    ['value' => 1, 'label' => 'Seg'],
                    ['value' => 2, 'label' => 'Ter'],
                    ['value' => 3, 'label' => 'Qua'],
                    ['value' => 4, 'label' => 'Qui'],
                    ['value' => 5, 'label' => 'Sex'],
                    ['value' => 6, 'label' => 'Sab'],
                    ['value' => 7, 'label' => 'Dom'],
                ],
            ],
        ], $accumulatedMeta);
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return is_array($incoming) ? $incoming : [];
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return ['value' => $value, '_meta' => $this->meta($meta)];
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key === 'alarm_clock' ? 'alarmClock' : $key;
    }
}
