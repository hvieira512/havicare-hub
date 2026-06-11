<?php

namespace App\Protocol\Adapter;

class FourPTouchAdapter implements DeviceAdapterInterface
{
    public function protocol(): string
    {
        return 'four-p-touch';
    }

    public function canDecode(string $raw): bool
    {
        return $this->parseFrame($raw) !== null;
    }

    public function decodeIncoming(string $raw, array $context = []): ?array
    {
        $frame = $this->parseFrame($raw);
        if ($frame === null) {
            return null;
        }

        $fields = $frame['content'] === '' ? [] : explode(',', $frame['content']);
        $type = (string)($fields[0] ?? '');
        if ($type === '') {
            return null;
        }

        $data = [
            'manufacturer' => $frame['manufacturer'],
            'deviceId' => $frame['deviceId'],
            'length' => $frame['length'],
            'raw' => $frame['content'],
            'fields' => array_slice($fields, 1),
        ];

        $this->enrichData($type, $data['fields'], $data);

        return [
            'type' => $type,
            'ident' => $frame['deviceId'],
            'ref' => 'w:update',
            'imei' => $frame['deviceId'],
            'data' => $data,
            'timestamp' => (int)round(microtime(true) * 1000),
        ];
    }

    public function encodeOutgoing(array $payload, array $context = []): string
    {
        $manufacturer = $this->frameValue((string)($payload['manufacturer'] ?? $context['manufacturer'] ?? '3G'));
        $deviceId = $this->frameValue((string)($payload['deviceId'] ?? $payload['imei'] ?? $context['deviceId'] ?? ''));
        $type = $this->frameValue((string)($payload['type'] ?? ''));
        if ($deviceId === '' || $type === '') {
            return '';
        }

        $fields = isset($payload['data']['fields']) && is_array($payload['data']['fields'])
            ? $payload['data']['fields']
            : [];
        $content = $type;
        if ($fields !== []) {
            $content .= ',' . implode(',', array_map(static fn (mixed $value): string => (string)$value, $fields));
        }

        return sprintf('[%s*%s*%04X*%s]', $manufacturer, $deviceId, strlen($content), $content);
    }

    private function parseFrame(string $raw): ?array
    {
        $message = trim($raw);
        if (preg_match('/^\[(CS|3G)\*(\d{10})\*([0-9A-Fa-f]{4})\*(.*)\]$/', $message, $matches) !== 1) {
            return null;
        }

        $content = $matches[4];
        $length = hexdec($matches[3]);
        if ($length !== strlen($content)) {
            return null;
        }

        return [
            'manufacturer' => $matches[1],
            'deviceId' => $matches[2],
            'length' => strtoupper($matches[3]),
            'content' => $content,
        ];
    }

    private function frameValue(string $value): string
    {
        return str_replace(['[', ']', '*'], '', trim($value));
    }

    private function enrichData(string $type, array $fields, array &$data): void
    {
        if ($type === 'LK') {
            $data['steps'] = $this->int($fields[0] ?? null);
            $data['tumblingCount'] = $this->int($fields[1] ?? null);
            $data['batteryPercent'] = $this->int($fields[2] ?? null);
            return;
        }

        if ($type === 'bphrt') {
            $data['systolic'] = $this->int($fields[0] ?? null);
            $data['diastolic'] = $this->int($fields[1] ?? null);
            $data['heartRate'] = $this->int($fields[2] ?? null);
            $data['heightCm'] = $this->int($fields[3] ?? null);
            $data['gender'] = $this->gender($fields[4] ?? null);
            $data['age'] = $this->int($fields[5] ?? null);
            $data['weightKg'] = $this->int($fields[6] ?? null);
            return;
        }

        if ($type === 'UD_LTE' || $type === 'AL_LTE') {
            $this->enrichPosition($fields, $data);
            if ($type === 'AL_LTE') {
                $this->enrichAlarm($data);
            }
            return;
        }

        if ($type === 'CONFIG') {
            $data['configAck'] = $fields[0] ?? null;
            $configs = [];
            foreach ($fields as $field) {
                if (!is_string($field) || !str_contains($field, ':')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode(':', $field, 2));
                if ($key !== '') {
                    $configs[$key] = $value;
                }
            }
            if ($configs !== []) {
                $data['configs'] = $configs;
            }
        }
    }

    private function enrichPosition(array $fields, array &$data): void
    {
        $gpsValid = strtoupper((string)($fields[2] ?? '')) === 'A';
        $lat = $this->coordinate($fields[3] ?? null, $fields[4] ?? null);
        $lon = $this->coordinate($fields[5] ?? null, $fields[6] ?? null);

        $data['date'] = $fields[0] ?? null;
        $data['timeUtc'] = $fields[1] ?? null;
        $data['gpsValid'] = $gpsValid;
        $data['source'] = $gpsValid ? 'gps' : 'lbs_wifi';
        $data['lat'] = $lat;
        $data['lon'] = $lon;
        $data['speed'] = $this->float($fields[7] ?? null);
        $data['direction'] = $this->float($fields[8] ?? null);
        $data['altitude'] = $this->float($fields[9] ?? null);
        $data['satellites'] = $this->int($fields[10] ?? null);
        $data['gsmSignal'] = $this->int($fields[11] ?? null);
        $data['batteryPercent'] = $this->int($fields[12] ?? null);
        $data['steps'] = $this->int($fields[13] ?? null);
        $data['tumblingCount'] = $this->int($fields[14] ?? null);
        $data['alarmCode'] = isset($fields[15]) ? strtoupper((string)$fields[15]) : null;
        $data['baseStationCount'] = $this->int($fields[16] ?? null);
        $data['networkType'] = $fields[17] ?? null;
        $data['mcc'] = isset($fields[18]) ? (string)$fields[18] : null;
        $data['mnc'] = isset($fields[19]) ? (string)$fields[19] : null;
        $data['lac'] = isset($fields[20]) ? (string)$fields[20] : null;
        $data['cellId'] = isset($fields[21]) ? (string)$fields[21] : null;
        $data['cellSignal'] = $this->int($fields[22] ?? null);
        $data['wifiCount'] = $this->int($fields[23] ?? null);

        $last = end($fields);
        if ($last !== false && is_numeric((string)$last)) {
            $data['accuracy'] = $this->float($last);
        }
    }

    private function enrichAlarm(array &$data): void
    {
        $alarm = isset($data['alarmCode']) ? hexdec((string)$data['alarmCode']) : 0;
        $data['sos'] = ($alarm & 0x00010000) !== 0;
        $data['lowBattery'] = ($alarm & 0x00020000) !== 0;
        $data['fall'] = ($alarm & 0x00200000) !== 0;
    }

    private function coordinate(mixed $value, mixed $direction): ?float
    {
        $float = $this->float($value);
        if ($float === null) {
            return null;
        }

        $direction = strtoupper((string)$direction);
        return in_array($direction, ['S', 'W'], true) ? -abs($float) : $float;
    }

    private function int(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric((string)$value) ? null : (int)$value;
    }

    private function float(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric((string)$value) ? null : (float)$value;
    }

    private function gender(mixed $value): ?string
    {
        return match ((string)$value) {
            '1' => 'male',
            '2' => 'female',
            default => null,
        };
    }
}
