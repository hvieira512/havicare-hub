<?php

namespace Hub\Command;

final class DeviceConfigurationCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function configsForProtocol(string $protocol): array
    {
        $configs = match ($protocol) {
            'wonlex-json' => self::wonlexConfigs(),
            'vivistar-iw' => self::vivistarConfigs(),
            default => [],
        };

        usort($configs, static function (array $a, array $b): int {
            $categoryA = (string)($a['category'] ?? '');
            $categoryB = (string)($b['category'] ?? '');
            if ($categoryA !== $categoryB) {
                return strcmp($categoryA, $categoryB);
            }

            $orderA = (int)($a['order'] ?? 0);
            $orderB = (int)($b['order'] ?? 0);
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        });

        return $configs;
    }

    public static function configForProtocol(string $protocol, string $key): ?array
    {
        foreach (self::configsForProtocol($protocol) as $entry) {
            if (($entry['key'] ?? '') === $key) {
                return $entry;
            }
        }

        return null;
    }

    public static function configForCommand(string $protocol, string $command): ?array
    {
        foreach (self::configsForProtocol($protocol) as $entry) {
            if (($entry['command'] ?? '') === $command) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{command: string, payload: array<string, mixed>}
     */
    public static function commandPayload(string $protocol, string $key, array $payload): array
    {
        $entry = self::configForProtocol($protocol, $key);
        if ($entry === null) {
            throw new \InvalidArgumentException("Unsupported {$protocol} configuration {$key}");
        }

        return [
            'command' => (string)$entry['command'],
            'payload' => match ($protocol) {
                'wonlex-json' => self::wonlexPayload($key, $payload),
                'vivistar-iw' => self::vivistarPayload($key, $payload),
                default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
            },
        ];
    }

    public static function validate(string $protocol, string $key, array $payload): ?string
    {
        if (self::configForProtocol($protocol, $key) === null) {
            return "Unsupported {$protocol} configuration {$key}";
        }

        try {
            self::commandPayload($protocol, $key, $payload);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private static function wonlexPayload(string $key, array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return match ($key) {
            'locationInterval' => ['intervalTime' => self::nonNegativeInt($payload['intervalTime'] ?? null, 'intervalTime')],
            'deviceMeasuringFrequency' => ['configs' => self::arrayField($payload['configs'] ?? null, 'configs')],
            'deviceConfig' => ['configs' => self::arrayField($payload['configs'] ?? null, 'configs')],
            'alarmClock' => ['alarmClock' => self::arrayField($payload['alarmClock'] ?? $payload['alarms'] ?? null, 'alarmClock')],
            'SOSNumber' => ['SOSNumber' => self::stringList($payload['numbers'] ?? [], 3, 'numbers')],
            'dnMedicationPlan' => ['plans' => self::arrayField($payload['plans'] ?? $payload['medicationPlan'] ?? null, 'plans')],
            'dnDevBindStatus' => ['bindStatus' => self::boolInt($payload['bindStatus'] ?? $payload['enabled'] ?? null, 'bindStatus')],
            default => throw new \InvalidArgumentException("Unsupported Wonlex configuration {$key}"),
        };
    }

    private static function vivistarPayload(string $key, array $payload): array
    {
        if (isset($payload['fields']) && is_array($payload['fields'])) {
            return ['fields' => array_map(static fn(mixed $value): string => (string)$value, $payload['fields'])];
        }

        $fields = match ($key) {
            'sosContacts' => self::stringList($payload['numbers'] ?? [], 3, 'numbers'),
            'phonebook' => self::vivistarPhonebook($payload['contacts'] ?? []),
            'workingMode' => self::vivistarWorkingMode($payload),
            'fallDetection' => [self::boolInt($payload['enabled'] ?? null, 'enabled')],
            'fallSensitivity' => [self::rangeInt($payload['sensitivity'] ?? null, 1, 3, 'sensitivity')],
            'whitelistSwitch' => [self::boolInt($payload['enabled'] ?? null, 'enabled')],
            'reminders' => self::vivistarReminders($payload),
            'autoHealthMeasurement' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
                self::positiveInt($payload['intervalMinutes'] ?? null, 'intervalMinutes'),
            ],
            'bloodPressureCalibration' => self::vivistarBloodPressureCalibration($payload),
            default => throw new \InvalidArgumentException("Unsupported Vivistar configuration {$key}"),
        };

        return ['fields' => array_map(static fn(mixed $value): string => (string)$value, $fields)];
    }

    private static function vivistarWorkingMode(array $payload): array
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

    private static function vivistarPhonebook(mixed $contacts): array
    {
        if (!is_array($contacts)) {
            throw new \InvalidArgumentException('contacts must be an array');
        }

        $fields = [];
        foreach (array_slice($contacts, 0, 10) as $contact) {
            if (!is_array($contact)) {
                throw new \InvalidArgumentException('each contact must be an object');
            }
            $phone = trim((string)($contact['phone'] ?? ''));
            if ($phone === '') {
                $fields[] = '';
                continue;
            }
            $name = (string)($contact['name'] ?? '');
            $encodedName = trim((string)($contact['encodedName'] ?? ''));
            $fields[] = ($encodedName !== '' ? $encodedName : self::utf16Hex($name)) . '|' . $phone;
        }

        return array_pad($fields, 10, '');
    }

    private static function vivistarReminders(array $payload): array
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
            $days = preg_replace('/[^0-9]/', '', (string)($item['days'] ?? ''));
            if ($days === '') {
                throw new \InvalidArgumentException('reminder days is required');
            }
            $enabled = self::boolInt($item['enabled'] ?? true, 'enabled');
            $type = self::rangeInt($item['type'] ?? null, 1, 3, 'type');
            $entries[] = "{$time},{$days},{$enabled},{$type}";
        }

        return [
            self::boolInt($payload['masterEnabled'] ?? null, 'masterEnabled'),
            count($entries),
            implode('@', $entries),
        ];
    }

    private static function vivistarBloodPressureCalibration(array $payload): array
    {
        if (isset($payload['values']) && is_array($payload['values'])) {
            return $payload['values'];
        }

        return [
            self::positiveInt($payload['systolic'] ?? null, 'systolic'),
            self::positiveInt($payload['diastolic'] ?? null, 'diastolic'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function wonlexConfigs(): array
    {
        return [
            self::entry('locationInterval', 'locationInterval', 'Intervalo de localização', 'number', ['intervalTime'], ['upDeviceConfig'], 'intervals', 10),
            self::entry('deviceMeasuringFrequency', 'deviceMeasuringFrequency', 'Frequência de medições', 'json', ['configs'], ['upDeviceConfig'], 'intervals', 20),
            self::entry('deviceConfig', 'deviceConfig', 'Configuração do dispositivo', 'json', ['configs'], ['upDeviceConfig'], 'system', 10),
            self::entry('alarmClock', 'alarmClock', 'Alarmes', 'json', ['alarmClock'], ['upDeviceConfig'], 'alerts', 10),
            self::entry('SOSNumber', 'SOSNumber', 'Números SOS', 'list', ['numbers'], ['upDeviceConfig'], 'contacts', 10, 3),
            self::entry('dnMedicationPlan', 'dnMedicationPlan', 'Plano de medicação', 'json', ['plans'], ['upDeviceConfig'], 'health', 10),
            self::entry('dnDevBindStatus', 'dnDevBindStatus', 'Estado de vinculação', 'toggle', ['bindStatus'], [], 'system', 20),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function vivistarConfigs(): array
    {
        return [
            self::entry('sosContacts', 'BP12', 'Contactos SOS', 'list', ['numbers'], ['AP12'], 'contacts', 10, 3),
            self::entry('phonebook', 'BP14', 'Lista telefónica', 'contacts', ['contacts'], ['AP14'], 'contacts', 20, 10),
            self::entry('workingMode', 'BP33', 'Modo de trabalho', 'workingMode', ['mode'], ['AP33'], 'system', 10),
            self::entry('fallDetection', 'BP76', 'Deteção de queda', 'toggle', ['enabled'], ['AP76'], 'alerts', 10),
            self::entry('fallSensitivity', 'BP77', 'Sensibilidade de queda', 'number', ['sensitivity'], ['AP77'], 'alerts', 20),
            self::entry('whitelistSwitch', 'BP84', 'Lista branca', 'toggle', ['enabled'], ['AP84'], 'system', 20),
            self::entry('reminders', 'BP85', 'Lembretes', 'reminders', ['masterEnabled', 'items'], ['AP85'], 'alerts', 30),
            self::entry('autoHealthMeasurement', 'BP86', 'Medição automática de saúde', 'intervalToggle', ['enabled', 'intervalMinutes'], ['AP86'], 'health', 10),
            self::entry('bloodPressureCalibration', 'BPJZ', 'Calibração da tensão arterial', 'bloodPressure', ['systolic', 'diastolic'], ['APJZ'], 'health', 20),
        ];
    }

    private static function entry(
        string $key,
        string $command,
        string $label,
        string $input,
        array $fields,
        array $expectedReplyTypes = [],
        string $category = 'general',
        int $order = 0,
        ?int $limit = null
    ): array
    {
        $entry = [
            'key' => $key,
            'command' => $command,
            'label' => $label,
            'kind' => 'config',
            'risk' => 'normal',
            'input' => $input,
            'fields' => $fields,
            'expectedReplyTypes' => $expectedReplyTypes,
            'category' => $category,
            'order' => $order,
        ];

        if ($limit !== null) {
            $entry['limit'] = $limit;
        }

        return $entry;
    }

    private static function arrayField(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an object or array");
        }
        return $value;
    }

    private static function stringList(mixed $value, int $max, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }
        return array_pad(array_map(static fn(mixed $item): string => trim((string)$item), array_slice($value, 0, $max)), $max, '');
    }

    private static function boolInt(mixed $value, string $field): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (int)$value;
        }
        throw new \InvalidArgumentException("{$field} must be boolean or 0/1");
    }

    private static function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_numeric((string)$value) || (int)$value < 0) {
            throw new \InvalidArgumentException("{$field} must be a non-negative integer");
        }
        return (int)$value;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_numeric((string)$value) || (int)$value <= 0) {
            throw new \InvalidArgumentException("{$field} must be a positive integer");
        }
        return (int)$value;
    }

    private static function rangeInt(mixed $value, int $min, int $max, string $field): int
    {
        $value = self::positiveInt($value, $field);
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("{$field} must be between {$min} and {$max}");
        }
        return $value;
    }

    private static function utf16Hex(string $value): string
    {
        $encoded = iconv('UTF-8', 'UTF-16BE//IGNORE', $value);
        return strtoupper(bin2hex($encoded !== false ? $encoded : $value));
    }
}
