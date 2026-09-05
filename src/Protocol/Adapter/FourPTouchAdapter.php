<?php

namespace Hub\Protocol\Adapter;

class FourPTouchAdapter implements DeviceAdapterInterface
{
    /**
     * Os tipos de trama de posição e de alarme do 4P Touch, por rede. Um `_LTE` novo entra
     * aqui uma vez, e não em cada sítio que os enumera -- falhar um deixava a trama a chegar
     * em `raw` sem virar telemetria, em silêncio.
     */
    public const LOCATION_FRAME_TYPES = ['UD', 'UD2', 'UD_WCDMA', 'UD_LTE'];
    public const ALARM_FRAME_TYPES = ['AL', 'AL_WCDMA', 'AL_LTE'];

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
            'timestamp' => (int) round(microtime(true) * 1000),
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
            $content .= ',' . implode(',', array_map(static fn (mixed $value): string => (string) $value, $fields));
        }

        $contentLength = strlen($content);
        if ($contentLength > 0xFFFF) {
            throw new \InvalidArgumentException('4P Touch content exceeds the protocol maximum of 65535 bytes');
        }

        return sprintf('[%s*%s*%04X*%s]', $manufacturer, $deviceId, $contentLength, $content);
    }

    private function parseFrame(string $raw): ?array
    {
        $message = trim($raw);
        if (preg_match('/^\[(CS|3G)\*(\d{10})\*([0-9A-Fa-f]{4})\*(.*)\]$/s', $message, $matches) !== 1) {
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

        if ($type === 'oxygen') {
            $data['measureType'] = $this->int($fields[0] ?? null);
            $data['spo2'] = $this->int($fields[1] ?? null);
            return;
        }

        if ($type === 'btemp2') {
            $data['measureType'] = $this->int($fields[0] ?? null);
            $data['temp'] = $this->float($fields[1] ?? null);
            return;
        }

        if ($this->isPositionType($type) || $this->isAlarmType($type)) {
            $this->enrichPosition($type, $fields, $data);
            if ($this->isAlarmType($type)) {
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
            return;
        }

        if ($type === 'TAKEPILLS') {
            $data['configAck'] = $fields[0] ?? null;
            return;
        }

        if ($type === 'WIFIINFOUP') {
            $data['wifiNameHex'] = $fields[0] ?? null;
            $data['wifiPasswordHex'] = $fields[1] ?? null;
            $data['wifiSsid'] = $fields[2] ?? null;
            $data['wifiName'] = $this->hexAscii($fields[0] ?? null);
            $data['wifiPassword'] = $this->hexAscii($fields[1] ?? null);
            return;
        }

        if ($type === 'TK') {
            $data['audioData'] = $fields[0] ?? null;
            return;
        }

        if ($type === 'VERNO') {
            $data['firmware'] = $fields[0] ?? null;
            return;
        }

        if ($type === 'TS') {
            $data['deviceTime'] = $fields[0] ?? null;
            return;
        }
    }

    private function enrichPosition(string $type, array $fields, array &$data): void
    {
        $gpsValid = strtoupper((string) ($fields[2] ?? '')) === 'A';
        $statusBits = $this->hexInt($fields[15] ?? null);
        $baseStationCount = max(0, $this->int($fields[16] ?? null) ?? 0);

        $data['date'] = $fields[0] ?? null;
        $data['timeUtc'] = $fields[1] ?? null;
        $data['gpsValid'] = $gpsValid;
        $data['source'] = $gpsValid ? 'gps' : 'lbs_wifi';
        $data['lat'] = $this->coordinate($fields[3] ?? null, $fields[4] ?? null);
        $data['lon'] = $this->coordinate($fields[5] ?? null, $fields[6] ?? null);
        $data['speed'] = $this->float($fields[7] ?? null);
        $data['direction'] = $this->float($fields[8] ?? null);
        $data['altitude'] = $this->float($fields[9] ?? null);
        $data['satellites'] = $this->int($fields[10] ?? null);
        $data['gsmSignal'] = $this->int($fields[11] ?? null);
        $data['batteryPercent'] = $this->int($fields[12] ?? null);
        $data['steps'] = $this->int($fields[13] ?? null);
        $data['tumblingCount'] = $this->int($fields[14] ?? null);
        $data['alarmCode'] = isset($fields[15]) ? strtoupper((string) $fields[15]) : null;
        $data['baseStationCount'] = $fields[16] ?? null;
        $data['connectedBaseStationCount'] = $this->int($fields[17] ?? null);
        $data['networkType'] = $this->networkTypeFromType($type);
        $data['mcc'] = isset($fields[18]) ? (string) $fields[18] : null;
        $data['mnc'] = isset($fields[19]) ? (string) $fields[19] : null;

        if ($statusBits !== null) {
            $this->applyStatusBits($statusBits, $data);
            $this->enrichAlarm($data);
        }

        $cursor = 20;
        $baseStations = [];
        for ($index = 0; $index < $baseStationCount; $index++) {
            if (!array_key_exists($cursor + 2, $fields)) {
                break;
            }

            $baseStations[] = array_filter([
                'lac' => $this->stringField($fields[$cursor] ?? null),
                'cellId' => $this->stringField($fields[$cursor + 1] ?? null),
                'gsmSignal' => $this->int($fields[$cursor + 2] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $cursor += 3;
        }

        if ($baseStations !== []) {
            $data['baseStations'] = $baseStations;
            $firstBase = $baseStations[0];
            $data['lac'] = $data['lac'] ?? ($firstBase['lac'] ?? null);
            $data['cellId'] = $data['cellId'] ?? ($firstBase['cellId'] ?? null);
            $data['cellSignal'] = $data['cellSignal'] ?? ($firstBase['gsmSignal'] ?? null);
        }

        $remaining = array_slice($fields, $cursor);
        $accuracy = null;
        if ($remaining !== []) {
            $last = end($remaining);
            if ($last !== false && is_numeric((string) $last)) {
                $accuracy = $this->float($last);
                array_pop($remaining);
            }
        }

        if ($accuracy !== null) {
            $data['accuracy'] = $accuracy;
        }

        if ($remaining !== []) {
            $wifiCount = $this->int($remaining[0] ?? null);
            if ($wifiCount !== null) {
                $data['wifiCount'] = $wifiCount;
                $wifiFields = array_slice($remaining, 1);
                $wifi = [];
                for ($index = 0; $index < $wifiCount; $index++) {
                    $offset = $index * 3;
                    if (!array_key_exists($offset + 2, $wifiFields)) {
                        break;
                    }

                    $wifi[] = array_filter([
                        'label' => $this->stringField($wifiFields[$offset] ?? null),
                        'mac' => $this->stringField($wifiFields[$offset + 1] ?? null),
                        'signal' => $this->int($wifiFields[$offset + 2] ?? null),
                    ], static fn (mixed $value): bool => $value !== null && $value !== '');
                }

                if ($wifi !== []) {
                    $data['wifi'] = $wifi;
                }
            }
        }
    }

    private function enrichAlarm(array &$data): void
    {
        $alarm = isset($data['alarmCode']) ? hexdec((string) $data['alarmCode']) : 0;
        $data['sos'] = ($alarm & 0x00010000) !== 0;
        $data['lowBattery'] = ($alarm & 0x00020000) !== 0;
        $data['outFenceAlarm'] = ($alarm & 0x00040000) !== 0;
        $data['inFenceAlarm'] = ($alarm & 0x00080000) !== 0;
        $data['removeAlarm'] = ($alarm & 0x00100000) !== 0;
        $data['fall'] = ($alarm & 0x00200000) !== 0;
        $data['abnormalHeartRateAlarm'] = ($alarm & 0x00400000) !== 0;
    }

    private function applyStatusBits(int $status, array &$data): void
    {
        $data['lowBatteryState'] = ($status & 0x00000001) !== 0;
        $data['outFenceState'] = ($status & 0x00000002) !== 0;
        $data['inFenceState'] = ($status & 0x00000004) !== 0;
        $data['watchState'] = ($status & 0x00000008) !== 0;
        $data['staticState'] = ($status & 0x00000010) !== 0;
    }

    private function coordinate(mixed $value, mixed $direction): ?float
    {
        $float = $this->float($value);
        if ($float === null) {
            return null;
        }

        $direction = strtoupper((string) $direction);
        return in_array($direction, ['S', 'W'], true) ? -abs($float) : $float;
    }

    private function isPositionType(string $type): bool
    {
        return in_array($type, self::LOCATION_FRAME_TYPES, true);
    }

    private function isAlarmType(string $type): bool
    {
        return in_array($type, self::ALARM_FRAME_TYPES, true);
    }

    private function networkTypeFromType(string $type): ?string
    {
        return match (true) {
            str_ends_with($type, '_LTE') => 'LTE',
            str_ends_with($type, '_WCDMA') => 'WCDMA',
            default => 'GSM',
        };
    }

    private function int(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric((string) $value) ? null : (int) $value;
    }

    private function float(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric((string) $value) ? null : (float) $value;
    }

    private function hexInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^[0-9A-Fa-f]+$/', $value) !== 1) {
            return null;
        }

        return hexdec($value);
    }

    private function gender(mixed $value): ?string
    {
        return match ((string) $value) {
            '1' => 'male',
            '2' => 'female',
            default => null,
        };
    }

    private function stringField(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function hexAscii(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^[0-9A-Fa-f]+$/', $value) !== 1 || strlen($value) % 2 !== 0) {
            return null;
        }

        $decoded = @hex2bin($value);
        if ($decoded === false) {
            return null;
        }

        return trim($decoded);
    }
}
