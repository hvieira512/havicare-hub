<?php

namespace Hub\Domain\Capability\AlarmClock;

/**
 * Handler do `alarm_clock` do 4P Touch.
 *
 * Chave nativa: 'alarmClock'
 * Forma nativa: { alarmClock: { alarms: [{time, enabled, mode, custom}] } }
 */
final class FourPTouch implements AlarmClockHandler
{
    use AlarmClockHelpers;

    public function nativeKey(): string
    {
        return 'alarmClock';
    }

    public function toNative(mixed $value): array
    {
        $alarms = $this->normalizeInput($value);
        if (count($alarms) > 3) {
            throw new \InvalidArgumentException('alarms must not contain more than 3 items');
        }

        return ['alarmClock' => ['alarms' => $alarms]];
    }

    public function fromNative(array $desired): array
    {
        $items = $desired['alarms'] ?? $desired['items'] ?? $desired;
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
            'alarms' => [
                [
                    'time' => '',
                    'enabled' => true,
                    'frequency' => 1,
                ],
            ],
        ];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return $accumulatedMeta;
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
    // Normalização da entrada nativa
    // ------------------------------------------------------------------

    private function normalizeInput(mixed $desired): array
    {
        if (is_string($desired)) {
            return $this->normalizeList([$desired]);
        }

        if (!is_array($desired)) {
            return [];
        }

        if ($desired === []) {
            return [];
        }

        if (isset($desired['fields']) && is_array($desired['fields'])) {
            return $this->parseFields($desired['fields']);
        }

        $list = $desired['alarms'] ?? $desired['alarmClock'] ?? $desired['items'] ?? null;
        if ($list !== null) {
            return $this->normalizeList(is_array($list) ? $list : [$list]);
        }

        if (array_is_list($desired)) {
            return $this->normalizeList($desired);
        }

        return $this->normalizeList([$desired]);
    }

    /** @return list<array{time: string, enabled: bool, mode: int, custom: string}> */
    private function normalizeList(array $alarms): array
    {
        $normalized = [];
        foreach (array_slice($alarms, 0, 3) as $alarm) {
            $normalized[] = $this->normalizeItem($alarm);
        }

        return $normalized;
    }

    private function normalizeItem(mixed $value): array
    {
        if (is_string($value)) {
            $parsed = $this->parseString($value);

            return [
                'time' => $parsed['time'],
                'enabled' => $parsed['enabled'],
                'mode' => $parsed['frequency'],
                'custom' => $parsed['custom'],
            ];
        }

        if (!is_array($value)) {
            return ['time' => '', 'enabled' => true, 'mode' => 1, 'custom' => ''];
        }

        if (array_key_exists('type', $value) && trim((string)($value['type'] ?? '')) !== '') {
            throw new \InvalidArgumentException('type is not supported for four-p-touch alarm_clock');
        }

        $time = $this->normalizeTime(
            (string)($value['time'] ?? $value['alarmTime'] ?? $value['reminderTime'] ?? ''),
        );
        $enabled = self::boolLikeToBool($value['enabled'] ?? $value['switchState'] ?? true);
        $frequency = $this->resolveFrequency($value);

        $custom = '';
        if ($frequency === 3) {
            $recurrence = is_array($value['recurrence'] ?? null) ? $value['recurrence'] : [];
            $custom = $this->normalizeDays(
                $recurrence['days'] ?? $value['custom'] ?? $value['days'] ?? $value['reminderCustom'] ?? null,
            );
        }

        return ['time' => $time, 'enabled' => $enabled, 'mode' => $frequency, 'custom' => $custom];
    }

    private function resolveFrequency(array $value): int
    {
        $recurrence = is_array($value['recurrence'] ?? null) ? $value['recurrence'] : [];
        $kind = strtolower(trim((string)($recurrence['kind'] ?? '')));

        return match ($kind) {
            'daily' => 2,
            'custom' => 3,
            'once' => 1,
            default => (int) max(1, min(3, (int)($value['frequency'] ?? $value['mode'] ?? $value['reminderFrequency'] ?? 1))),
        };
    }

    // ------------------------------------------------------------------
    // Normalização de hora / dia
    // ------------------------------------------------------------------

    private function normalizeTime(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches) === 1) {
            $hour = (int)$matches[1];
            $minute = (int)$matches[2];
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                throw new \InvalidArgumentException('alarm time must use valid 24h times');
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }

        if (preg_match('/^(\d{2})(\d{2})$/', $value, $matches) === 1) {
            $hour = (int)$matches[1];
            $minute = (int)$matches[2];
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                throw new \InvalidArgumentException('alarm time must use valid 24h times');
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }

        if ($value === '') {
            return '';
        }

        throw new \InvalidArgumentException('alarm time must use HH:MM or HHmm');
    }

    private function normalizeDays(mixed $value): string
    {
        if (is_array($value)) {
            $days = self::dayMaskFromList($value);
            if ($days !== '') {
                return $days;
            }
        }

        $days = trim((string)$value);
        if (preg_match('/^[01]{7}$/', $days) === 1) {
            return $days;
        }

        if (preg_match('/^[1-7]+$/', $days) === 1) {
            return self::dayMaskFromList(str_split($days));
        }

        throw new \InvalidArgumentException('alarm custom days must be a 7-digit 0/1 mask');
    }

    /** @param list<mixed> $days */
    private static function dayMaskFromList(array $days): string
    {
        $mask = array_fill(0, 7, '0');
        foreach ($days as $day) {
            $value = (int)$day;
            if ($value === 7) {
                $mask[0] = '1';
                continue;
            }
            if ($value >= 1 && $value <= 6) {
                $mask[$value] = '1';
            }
        }

        return implode('', $mask);
    }

    // ------------------------------------------------------------------
    // Parsing de string
    // ------------------------------------------------------------------

    /** @return list<array{time: string, enabled: bool, mode: int, custom: string}> */
    private function parseFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (is_string($field) && trim($field) !== '') {
                $parsed = $this->parseString($field);
                $result[] = [
                    'time' => $parsed['time'],
                    'enabled' => $parsed['enabled'],
                    'mode' => $parsed['frequency'],
                    'custom' => $parsed['custom'],
                ];
            }
        }

        return $result;
    }

    /** @return array{time: string, enabled: bool, frequency: int, custom: string} */
    private function parseString(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('alarm entry must not be empty');
        }

        if (preg_match('/^(\d{1,2}:\d{2}|\d{4})-(0|1)-(1|2|3)(?:-([01]{7}))?$/', $value, $matches) === 1) {
            $time = $this->normalizeTime($matches[1]);
            $enabled = $matches[2] === '1';
            $frequency = (int)$matches[3];
            $custom = $matches[4] ?? '';

            if ($frequency === 3 && $custom !== '') {
                $custom = $this->normalizeDays($custom);
            }

            return ['time' => $time, 'enabled' => $enabled, 'frequency' => $frequency, 'custom' => $custom];
        }

        throw new \InvalidArgumentException('alarm entry must use HH:MM-switch-frequency[-days]');
    }

    // ------------------------------------------------------------------
    // Nativo → Público
    // ------------------------------------------------------------------

    public static function publicItem(mixed $item): array
    {
        if (!is_array($item)) {
            return [];
        }

        $mode = (int)($item['mode'] ?? $item['frequency'] ?? $item['reminderFrequency'] ?? 1);
        $kind = match ($mode) {
            2 => 'daily',
            3 => 'custom',
            default => 'once',
        };

        $payload = [
            'time' => self::normalizeTimeStatic(
                (string)($item['time'] ?? $item['alarmTime'] ?? $item['reminderTime'] ?? ''),
            ),
            'enabled' => self::boolLikeToBool($item['enabled'] ?? $item['switchState'] ?? true),
            'recurrence' => ['kind' => $kind],
        ];

        if ($kind === 'custom') {
            $days = self::parseDayMaskToList(
                (string)($item['custom'] ?? $item['days'] ?? $item['reminderCustom'] ?? ''),
            );
            if ($days !== []) {
                $payload['recurrence']['days'] = $days;
            }
        }

        return $payload;
    }

    private static function normalizeTimeStatic(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) === 1) {
            $h = (int)$m[1];
            $mi = (int)$m[2];
            if ($h >= 0 && $h <= 23 && $mi >= 0 && $mi <= 59) {
                return sprintf('%02d:%02d', $h, $mi);
            }
        }

        if (preg_match('/^(\d{2})(\d{2})$/', $value, $m) === 1) {
            $h = (int)$m[1];
            $mi = (int)$m[2];
            if ($h >= 0 && $h <= 23 && $mi >= 0 && $mi <= 59) {
                return sprintf('%02d:%02d', $h, $mi);
            }
        }

        throw new \InvalidArgumentException('alarm time must use HH:MM or HHmm');
    }
}
