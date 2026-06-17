<?php

namespace Hub;

final class DeviceEventDecoder
{
    /**
     * @return array<int, array{feature: string, nativeType: string, value: array, extra?: array}>
     */
    public function decode(DeviceSession $session, array $decoded): array
    {
        $nativeType = (string)($decoded['type'] ?? '');
        if ($nativeType === '' || $nativeType === 'login') {
            return [];
        }

        $payload = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;

        $events = match ($session->protocol) {
            'wonlex-json' => $this->decodeWonlex($nativeType, $payload),
            'vivistar-iw' => $this->decodeVivistar($nativeType, $payload),
            'four-p-touch' => $this->decodeFourPTouch($nativeType, $payload),
            default => [],
        };

        return array_values(array_filter($events, 'is_array'));
    }

    private function decodeWonlex(string $nativeType, array $payload): array
    {
        return match ($nativeType) {
            'upHeartRate' => [$this->event('heart_rate', $nativeType, $payload)],
            'upBO' => [$this->event('blood_oxygen', $nativeType, $payload)],
            'upBP' => [$this->event('blood_pressure', $nativeType, $payload)],
            'upBS' => [$this->event('blood_sugar', $nativeType, $payload)],
            'upBodyTemperature' => [$this->event('temperature', $nativeType, $payload)],
            'upBattery' => [$this->event('battery', $nativeType, $payload)],
            'heartbeat' => array_values(array_filter([
                $this->event('heartbeat', $nativeType, $payload, $payload),
                $this->event('battery', $nativeType, $payload, $payload),
            ])),
            'upLocation' => [$this->event('location', $nativeType, $payload, $payload)],
            'upStep', 'upKcal', 'upDistance', 'upTodayActivity', 'upRun', 'upWalk' => [$this->event('activity', $nativeType, $payload, $payload)],
            'upGetDevConfig', 'upDeviceConfig' => [$this->event('device_config', $nativeType, $payload, $payload)],
            'upWeather' => [$this->event('weather', $nativeType, $payload, $payload)],
            'upBatch' => $this->decodeWonlexBatch($nativeType, $payload),
            default => [],
        };
    }

    private function decodeWonlexBatch(string $nativeType, array $payload): array
    {
        $events = [];
        if (isset($payload['heartRate'])) {
            $events[] = $this->event('heart_rate', $nativeType, $payload);
        }
        if (isset($payload['bp']) && is_string($payload['bp'])) {
            $parts = preg_split('/[\\/,-]+/', $payload['bp']) ?: [];
            $events[] = $this->event('blood_pressure', $nativeType, [
                'systolic' => $parts[0] ?? null,
                'diastolic' => $parts[1] ?? null,
                'pulse' => $parts[2] ?? null,
            ], $payload);
        }
        if (isset($payload['bo'])) {
            $events[] = $this->event('blood_oxygen', $nativeType, ['spo2' => $payload['bo']], $payload);
        }

        return array_values(array_filter($events));
    }

    private function decodeVivistar(string $nativeType, array $payload): array
    {
        return match ($nativeType) {
            'AP02' => [$this->decodeVivistarAp02($payload)],
            'AP49' => [$this->event('heart_rate', $nativeType, $payload)],
            'APHT' => [
                $this->event('heart_rate', $nativeType, $payload),
                $this->event('blood_pressure', $nativeType, $payload),
            ],
            'APHP' => array_values(array_filter([
                $this->event('heart_rate', $nativeType, $payload),
                $this->event('blood_pressure', $nativeType, $payload),
                $this->event('blood_oxygen', $nativeType, $payload),
                $this->event('blood_sugar', $nativeType, $payload),
            ])),
            'AP50' => [
                $this->event('temperature', $nativeType, $payload),
                $this->event('battery', $nativeType, ['battery' => $payload['battery'] ?? null]),
            ],
            'AP10' => array_values(array_filter([
                $this->event('alarm', $nativeType, $payload, $this->only($payload, [
                    'lat', 'lon', 'gpsValid', 'speed', 'direction', 'gsmSignal', 'satelliteCount',
                    'battery', 'mcc', 'mnc', 'lac', 'cellId', 'language',
                    'replyAddressRequested', 'mobileLinkRequested', 'wifiRaw', 'date', 'timeUtc',
                ])),
                $this->event('location', $nativeType, $payload, $this->only($payload, [
                    'alarmCode', 'sos', 'lowBattery', 'fall', 'wearingNotice', 'battery', 'language',
                ])),
                $this->event('battery', $nativeType, ['battery' => $payload['battery'] ?? null]),
            ])),
            'AP03' => [
                $this->event('heartbeat', $nativeType, $payload, $payload),
                $this->event('battery', $nativeType, ['battery' => $payload['battery'] ?? null]),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
            ],
            'AP12', 'AP14', 'AP28', 'AP33', 'AP40',
            'AP76', 'AP77', 'AP84', 'AP85', 'AP86',
            'APJZ', 'AP43' => [
                $this->event('device_config', $nativeType, $payload, $payload),
            ],
            'AP16', 'AP87', 'APXL', 'APXY', 'APXT', 'APXZ' => [],
            default => [],
        };
    }

    private function decodeVivistarAp02(array $payload): ?array
    {
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
        $baseStations = $this->parseVivistarBaseStations((string)($fields[5] ?? ''));
        $wifi = $this->parseVivistarWifi((string)($fields[7] ?? ''));
        $firstBase = $baseStations[0] ?? [];

        return $this->event('location', 'AP02', array_filter([
            'source' => 'vivistar-ap02',
            'gpsValid' => false,
            'mcc' => $this->stringField($fields[3] ?? null),
            'mnc' => $this->stringField($fields[4] ?? null),
            'lac' => $firstBase['lac'] ?? null,
            'cellId' => $firstBase['cellId'] ?? null,
            'gsmSignal' => $firstBase['gsmSignal'] ?? null,
            'accuracyMeters' => null,
            'baseStations' => $baseStations,
            'wifi' => $wifi,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''), [
            'source' => 'vivistar-ap02',
            'raw' => $payload['raw'] ?? '',
            'fields' => $fields,
            'replyFlag' => $this->intField($fields[1] ?? null),
            'baseCount' => $this->intField($fields[2] ?? null),
            'wifiCount' => $this->intField($fields[6] ?? null),
            'baseStations' => $baseStations,
            'wifi' => $wifi,
        ]);
    }

    private function decodeFourPTouch(string $nativeType, array $payload): array
    {
        return match (true) {
            $nativeType === 'LK' => array_values(array_filter([
                $this->event('heartbeat', $nativeType, $payload, $payload),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $nativeType === 'bphrt' => [
                $this->event('blood_pressure', $nativeType, $payload),
                $this->event('heart_rate', $nativeType, $payload),
            ],
            $nativeType === 'oxygen' => [
                $this->event('blood_oxygen', $nativeType, $payload, $payload),
            ],
            $this->isFourPTouchPosition($nativeType) => array_values(array_filter([
                $this->event('location', $nativeType, $payload, $payload),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $this->isFourPTouchAlarm($nativeType) => array_values(array_filter([
                $this->event('location', $nativeType, $payload, $payload),
                $this->event('alarm', $nativeType, $payload, $this->only($payload, [
                    'lat', 'lon', 'gpsValid', 'speed', 'direction', 'gsmSignal', 'satellites',
                    'batteryPercent', 'mcc', 'mnc', 'lac', 'cellId', 'networkType',
                ])),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $nativeType === 'CONFIG' => [$this->event('device_config', $nativeType, $payload, $payload)],
            default => [],
        };
    }

    private function isFourPTouchPosition(string $nativeType): bool
    {
        return in_array($nativeType, ['UD', 'UD2', 'UD_WCDMA', 'UD_LTE'], true);
    }

    private function isFourPTouchAlarm(string $nativeType): bool
    {
        return in_array($nativeType, ['AL', 'AL_WCDMA', 'AL_LTE'], true);
    }

    private function event(string $feature, string $nativeType, array $payload, array $extra = []): ?array
    {
        $value = FeatureNormalizer::normalize($feature, $payload);
        if ($value === []) {
            return null;
        }

        return array_filter([
            'feature' => $feature,
            'nativeType' => $nativeType,
            'value' => $value,
            'extra' => $this->extra($extra, $value),
        ], static fn (mixed $field): bool => $field !== []);
    }

    private function extra(array $payload, array $value): array
    {
        $extra = [];
        $normalizedAliases = [
            'configAck' => 'ack',
            'configs' => 'settings',
            'weather' => 'summary',
            'reporttime' => 'reportedAt',
            'reportTime' => 'reportedAt',
            'temperature' => 'temperatureCelsius',
            'temp' => 'temperatureCelsius',
            'lowTemp' => 'lowCelsius',
            'lowTemperature' => 'lowCelsius',
            'highTemp' => 'highCelsius',
            'highTemperature' => 'highCelsius',
            'humidity' => 'humidityPercent',
        ];
        foreach ($payload as $key => $field) {
            if ($key === 'source') {
                $rawSource = is_string($field) ? trim($field) : '';
                $normalizedSource = is_string($value['source'] ?? null) ? trim((string)$value['source']) : '';
                if ($rawSource !== '' && $normalizedSource !== '' && $rawSource !== $normalizedSource) {
                    $extra['sourceRaw'] = $rawSource;
                }
                continue;
            }
            if (isset($normalizedAliases[$key]) && array_key_exists($normalizedAliases[$key], $value)) {
                continue;
            }
            if (!is_string($key) || array_key_exists($key, $value) || $key === 'fields' || $key === 'raw') {
                continue;
            }
            if ($field !== null && $field !== '') {
                $extra[$key] = $field;
            }
        }

        return $extra;
    }

    private function only(array $payload, array $keys): array
    {
        return array_intersect_key($payload, array_flip($keys));
    }

    /**
     * @return array<int, array{lac?: string, cellId?: string, gsmSignal?: int}>
     */
    private function parseVivistarBaseStations(string $field): array
    {
        $stations = [];
        foreach (explode(',', $field) as $entry) {
            $parts = array_map('trim', explode('|', $entry));
            if (count($parts) < 3) {
                continue;
            }

            $stations[] = array_filter([
                'lac' => $parts[0] !== '' ? $parts[0] : null,
                'cellId' => $parts[1] !== '' ? $parts[1] : null,
                'gsmSignal' => $this->signalFromDbm($parts[2] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $stations;
    }

    /**
     * @return array<int, array{label?: string, mac?: string, gsmSignal?: int}>
     */
    private function parseVivistarWifi(string $field): array
    {
        $wifi = [];
        foreach (explode('&', $field) as $entry) {
            $parts = array_map('trim', explode('|', $entry));
            if (count($parts) < 3) {
                continue;
            }

            $wifi[] = array_filter([
                'label' => $parts[0] !== '' ? $parts[0] : null,
                'mac' => $parts[1] !== '' ? $parts[1] : null,
                'gsmSignal' => $this->signalFromDbm($parts[2] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $wifi;
    }

    private function signalFromDbm(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric((string)$value)) {
            return null;
        }

        return max(0, 150 - abs((int)$value));
    }

    private function intField(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric((string)$value) ? null : (int)$value;
    }

    private function stringField(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }
}
