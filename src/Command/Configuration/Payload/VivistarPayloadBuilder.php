<?php

namespace Hub\Command\Configuration\Payload;

final class VivistarPayloadBuilder extends ConfigurationPayloadBuilder
{
    public static function build(string $key, array $payload): array
    {
        if (isset($payload['fields']) && is_array($payload['fields'])) {
            return ['fields' => array_map(static fn(mixed $value): string => (string)$value, $payload['fields'])];
        }

        $fields = match ($key) {
            'sosContacts' => self::stringList($payload['numbers'] ?? [], 3, 'numbers'),
            'phonebook' => self::phonebook($payload),
            'call_whitelist' => self::callWhitelist($payload),
            'pushMessage' => [self::utf16Hex(self::requiredString($payload['message'] ?? null, 'message'))],
            'workingMode' => self::workingMode($payload),
            'fallDetection' => [self::boolInt($payload['enabled'] ?? null, 'enabled')],
            'fallSensitivity' => [self::rangeInt($payload['sensitivity'] ?? null, 1, 3, 'sensitivity')],
            'whitelist_enabled' => [self::boolInt($payload['enabled'] ?? null, 'enabled')],
            'reminders' => self::reminders($payload),
            'autoHealthMeasurement' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
                self::nonNegativeInt($payload['intervalMinutes'] ?? null, 'intervalMinutes'),
            ],
            default => throw new \InvalidArgumentException("Unsupported Vivistar configuration {$key}"),
        };

        return ['fields' => array_map(static fn(mixed $value): string => (string)$value, $fields)];
    }

    private static function workingMode(array $payload): array
    {
        $mode = self::rangeInt($payload['mode'] ?? null, 1, 8, 'mode');
        if ($mode !== 8) {
            if (!in_array($mode, [1, 2, 3], true)) {
                throw new \InvalidArgumentException('mode must be 1, 2, 3, or 8');
            }

            return [$mode];
        }

        $interval = self::positiveInt($payload['intervalSeconds'] ?? null, 'intervalSeconds');
        if ($interval < 30) {
            throw new \InvalidArgumentException('intervalSeconds must be at least 30 for mode 8');
        }

        return [8, $interval, self::boolInt($payload['gpsEnabled'] ?? null, 'gpsEnabled')];
    }

    private static function callWhitelist(array $payload): array
    {
        return self::phonebook($payload);
    }

    private static function phonebook(array $payload): array
    {
        $contacts = $payload['contacts'] ?? $payload['numbers'] ?? $payload;
        if (!is_array($contacts)) {
            throw new \InvalidArgumentException('contacts must be an array');
        }

        $fields = [];
        foreach (array_slice($contacts, 0, 10) as $contact) {
            if (is_array($contact)) {
                $name = trim((string)($contact['name'] ?? ''));
                $phone = trim((string)($contact['phone'] ?? ''));
                if ($phone === '') {
                    throw new \InvalidArgumentException('phone is required');
                }
                $fields[] = $name !== '' ? "{$name}|{$phone}" : "|{$phone}";
                continue;
            }
            $phone = trim((string)$contact);
            if ($phone === '') {
                $fields[] = '';
                continue;
            }
            $fields[] = '|' . $phone;
        }

        return array_pad($fields, 10, '');
    }

    private static function reminders(array $payload): array
    {
        $items = $payload['items'] ?? [];
        if (!is_array($items)) {
            throw new \InvalidArgumentException('items must be an array');
        }

        $entries = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('each reminder item must be an object');
            }
            $time = preg_replace('/[^0-9]/', '', (string)($item['time'] ?? ''));
            if (!is_string($time) || strlen($time) !== 4) {
                throw new \InvalidArgumentException('reminder time must be HH:mm or HHmm');
            }
            $enabled = self::boolInt($item['enabled'] ?? true, 'enabled');
            if (!array_key_exists('type', $item) || trim((string)($item['type'] ?? '')) === '') {
                throw new \InvalidArgumentException('type is required');
            }
            $type = self::rangeInt($item['type'], 1, 3, 'type');
            $days = self::reminderDays($item);
            if (($item['recurrence']['kind'] ?? null) === 'custom' && $days === '') {
                throw new \InvalidArgumentException('reminder days is required');
            }
            $entries[] = "{$time},{$days},{$enabled},{$type}";
        }

        return [
            self::boolInt($payload['masterEnabled'] ?? true, 'masterEnabled'),
            count($entries),
            implode('@', $entries),
        ];
    }

    private static function reminderDays(array $item): string
    {
        $recurrence = is_array($item['recurrence'] ?? null) ? $item['recurrence'] : [];
        $kind = strtolower(trim((string)($recurrence['kind'] ?? '')));
        if ($kind === 'daily') {
            return '1234567';
        }
        if ($kind === 'custom') {
            $daysValue = $recurrence['days'] ?? $item['days'] ?? $item['custom'] ?? '';
            if (is_array($daysValue)) {
                return self::formatDayList($daysValue);
            }
            $days = preg_replace('/[^0-9]/', '', (string)$daysValue);
            if ($days !== '') {
                return $days;
            }
        }
        if ($kind === 'once') {
            return '';
        }

        return preg_replace('/[^0-9]/', '', (string)($item['days'] ?? '')) ?: '';
    }

    private static function formatDayList(array $days): string
    {
        $normalized = [];
        foreach ($days as $day) {
            $value = (int)$day;
            if ($value >= 1 && $value <= 7) {
                $normalized[$value] = true;
            }
        }
        $ordered = array_keys($normalized);
        sort($ordered, SORT_NUMERIC);

        return implode('', array_map('strval', $ordered));
    }
}
