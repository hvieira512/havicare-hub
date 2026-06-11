<?php

namespace App\Hub;

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
                $this->event('battery', $nativeType, ['battery' => $payload['battery'] ?? null], $payload),
            ],
            'AP03' => [
                $this->event('heartbeat', $nativeType, $payload, $payload),
                $this->event('battery', $nativeType, ['battery' => $payload['battery'] ?? null], $payload),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null], $payload),
            ],
            'AP12', 'AP14', 'AP16', 'AP28', 'AP33', 'AP40',
            'AP76', 'AP77', 'AP84', 'AP85', 'AP86', 'AP87',
            'APJZ', 'APXL', 'APXY', 'APXT', 'APXZ' => [
                $this->event('device_config', $nativeType, $payload, $payload),
            ],
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
        ], static fn (mixed $value): bool => $value !== null && $value !== ''), [
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
        return match ($nativeType) {
            'LK' => array_values(array_filter([
                $this->event('heartbeat', $nativeType, $payload, $payload),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null], $payload),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null], $payload),
            ])),
            'bphrt' => [
                $this->event('blood_pressure', $nativeType, $payload),
                $this->event('heart_rate', $nativeType, $payload),
            ],
            'UD_LTE' => array_values(array_filter([
                $this->event('location', $nativeType, $payload, $payload),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null], $payload),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null], $payload),
            ])),
            'AL_LTE' => array_values(array_filter([
                $this->event('location', $nativeType, $payload, $payload),
                $this->event('alarm', $nativeType, $payload, $payload),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null], $payload),
            ])),
            'CONFIG' => [$this->event('device_config', $nativeType, $payload, $payload)],
            default => [],
        };
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
        foreach ($payload as $key => $field) {
            if (!is_string($key) || array_key_exists($key, $value) || $key === 'fields' || $key === 'raw') {
                continue;
            }
            if ($field !== null && $field !== '') {
                $extra[$key] = $field;
            }
        }

        return $extra;
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
