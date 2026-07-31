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
            'upBP' => array_values(array_filter([
                $this->event('blood_pressure', $nativeType, $payload),
                $this->heartRateFromBloodPressure($nativeType, $payload),
            ])),
            'upBS' => [$this->event('blood_sugar', $nativeType, $payload)],
            'upBodyTemperature' => [$this->event('temperature', $nativeType, $payload)],
            'upBreathe' => [$this->event('breath_rate', $nativeType, $payload)],
            'upECG' => [$this->event('ecg', $nativeType, $payload, $payload)],
            'upHRV' => [$this->event('hrv', $nativeType, $payload, $payload)],
            'upPPG' => [$this->event('ppg', $nativeType, $payload, $payload)],
            'upRR' => [$this->event('rr_interval', $nativeType, $payload, $payload)],
            'upBattery' => [$this->event('battery', $nativeType, $payload)],
            'heartbeat' => array_values(array_filter([
                $this->event('heartbeat', $nativeType, $payload, $payload),
                $this->event('battery', $nativeType, $payload, $payload),
            ])),
            'upLocation' => [$this->locationEvent($nativeType, $payload, $payload)],
            'upStep', 'upKcal', 'upDistance', 'upTodayActivity', 'upRun', 'upWalk' => [$this->event('activity', $nativeType, $payload, $payload)],
            'upSleep' => [$this->event('sleep', $nativeType, $payload, $payload)],
            'upDeviceConfig' => [$this->event('device_config', $nativeType, $payload, $payload)],
            'upWeather' => [$this->event('weather', $nativeType, $payload, $payload)],
            'upShutdown' => [$this->event('device_state', $nativeType, ['state' => 'shutdown'] + $payload, $payload)],
            'upReset' => [$this->event('device_state', $nativeType, ['state' => 'factory_reset'] + $payload, $payload)],
            'upBatch' => $this->decodeWonlexBatch($nativeType, $payload),
            default => [],
        };
    }

    private function decodeWonlexBatch(string $nativeType, array $payload): array
    {
        $dataType = trim((string)($payload['dataType'] ?? ''));
        $data = trim((string)($payload['data'] ?? ''));
        if ($dataType === '' && (isset($payload['heartRate']) || isset($payload['bp']) || isset($payload['bo']))) {
            $events = [];
            if (isset($payload['heartRate'])) {
                $events[] = $this->event('heart_rate', $nativeType, $payload);
            }
            if (isset($payload['bp']) && is_string($payload['bp'])) {
                $events[] = $this->event('blood_pressure', $nativeType, ['data' => $payload['bp']], $payload);
                $events[] = $this->heartRateFromBloodPressure($nativeType, ['data' => $payload['bp']]);
            }
            if (isset($payload['bo'])) {
                $events[] = $this->event('blood_oxygen', $nativeType, ['spo2' => $payload['bo']], $payload);
            }
            return array_values(array_filter($events, 'is_array'));
        }
        $times = array_map('trim', explode(',', (string)($payload['dataTime'] ?? '')));
        if ($dataType === '' || $data === '') {
            return [];
        }

        if ($dataType === 'upBP') {
            $measurements = str_contains($data, ';') ? explode(';', $data) : [$data];
        } else {
            $measurements = array_map('trim', explode(',', $data));
        }

        $events = [];
        foreach ($measurements as $index => $measurement) {
            $sample = array_filter([
                'data' => trim((string)$measurement),
                'measuredAt' => isset($times[$index]) && is_numeric($times[$index]) ? (int)$times[$index] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
            $extra = $payload;
            if (isset($sample['measuredAt'])) {
                $extra['measuredAt'] = $sample['measuredAt'];
            }
            if ($dataType === 'upHeartRate') {
                $events[] = $this->event('heart_rate', $nativeType, $sample, $extra);
            } elseif ($dataType === 'upBP') {
                $events[] = $this->event('blood_pressure', $nativeType, $sample, $extra);
                $events[] = $this->heartRateFromBloodPressure($nativeType, $sample);
            } elseif ($dataType === 'upBO') {
                $events[] = $this->event('blood_oxygen', $nativeType, $sample, $extra);
            } elseif ($dataType === 'upBodyTemperature') {
                $events[] = $this->event('temperature', $nativeType, $sample, $extra);
            } elseif ($dataType === 'upBreathe') {
                $events[] = $this->event('breath_rate', $nativeType, $sample, $extra);
            }
        }

        return array_values(array_filter($events, 'is_array'));
    }

    private function decodeVivistar(string $nativeType, array $payload): array
    {
        return match ($nativeType) {
            'AP01' => [$this->locationEvent($nativeType, $payload, $payload)],
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
                    'alarmCode',
                    'lat', 'lon', 'gpsValid', 'speed', 'direction', 'gsmSignal', 'satelliteCount',
                    'battery', 'mcc', 'mnc', 'lac', 'cellId', 'language',
                    'replyAddressRequested', 'mobileLinkRequested', 'wifiRaw', 'date', 'timeUtc',
                ])),
                $this->locationEvent($nativeType, $payload, $this->only($payload, [
                    'battery', 'language',
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

        return $this->locationEvent('AP02', array_filter([
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
            $nativeType === 'btemp2' => [
                $this->event('temperature', $nativeType, $payload, $payload),
            ],
            $this->isFourPTouchPosition($nativeType) => array_values(array_filter([
                $this->locationEvent($nativeType, $payload, $payload),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $this->isFourPTouchAlarm($nativeType) => array_values(array_filter([
                $this->locationEvent($nativeType, $payload, $payload),
                $this->event('alarm', $nativeType, $payload, $this->only($payload, [
                    'alarmCode',
                    'lat', 'lon', 'gpsValid', 'speed', 'direction', 'gsmSignal', 'satellites',
                    'batteryPercent', 'mcc', 'mnc', 'lac', 'cellId', 'networkType',
                ])),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $nativeType === 'CONFIG', $nativeType === 'TAKEPILLS' => [$this->event('device_config', $nativeType, $payload, $payload)],
            $nativeType === 'VERNO' => [$this->event('firmware_version', $nativeType, $payload, $payload)],
            $nativeType === 'TS' => [$this->event('device_status', $nativeType, $payload, $payload)],
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

    private function locationEvent(string $nativeType, array $payload, array $extra = []): ?array
    {
        $payload['radioType'] = $payload['radioType'] ?? $payload['networkType'] ?? match ($nativeType) {
            'UD', 'UD2', 'AL' => 'gsm',
            'UD_WCDMA', 'AL_WCDMA' => 'wcdma',
            'UD_LTE', 'AL_LTE' => 'lte',
            default => null,
        };
        $payload['reportKind'] = $payload['reportKind'] ?? match ($nativeType) {
            'UD2' => 'replay',
            'AL', 'AL_WCDMA', 'AL_LTE', 'AP10' => 'alarm',
            'UD', 'UD_WCDMA', 'UD_LTE', 'AP01' => 'periodic',
            'upLocation' => match ((string)($payload['dataType'] ?? $payload['DataType'] ?? '')) {
                '0' => 'periodic',
                '1' => 'requested',
                default => null,
            },
            default => null,
        };

        return $this->event('location', $nativeType, $payload, $extra);
    }

    private function heartRateFromBloodPressure(string $nativeType, array $payload): ?array
    {
        $pulse = $payload['pulse'] ?? $payload['pulseBpm'] ?? $payload['heartRate'] ?? $payload['hr'] ?? null;
        if ($pulse === null) {
            $rawData = $payload['data'] ?? $payload['date'] ?? null;
            if (is_string($rawData) && str_contains($rawData, '/')) {
                $parts = preg_split('/[\/,\-]+/', $rawData) ?: [];
                $pulse = $parts[2] ?? null;
            }
        }

        return $this->event('heart_rate', $nativeType, [
            'pulse' => $pulse,
        ]);
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
            'wifi' => 'wifiAccessPoints',
            'baseStation' => 'baseStations',
            'workingMode' => 'workMode',
            'battery' => 'batteryPercent',
            'batteryLevel' => 'batteryPercent',
            'rollsFrequency' => 'rollFrequency',
            'fortification' => 'fortificationState',
            'removeAlarm' => 'wearingNotice',
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
            if ($key === 'alarmCode') {
                if ($field !== null && $field !== '') {
                    $extra['rawCode'] = (string)$field;
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

            $rawSignal = $this->intField($parts[2] ?? null);
            $stations[] = array_filter([
                'lac' => $parts[0] !== '' ? $parts[0] : null,
                'cellId' => $parts[1] !== '' ? $parts[1] : null,
                'gsmSignal' => $this->legacySignalStrength($rawSignal),
                'signalStrengthDbm' => $this->vivistarSignalDbm($rawSignal),
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

            $rawSignal = $this->intField($parts[2] ?? null);
            $wifi[] = array_filter([
                'label' => $parts[0] !== '' ? $parts[0] : null,
                'mac' => $parts[1] !== '' ? $parts[1] : null,
                'gsmSignal' => $this->legacySignalStrength($rawSignal),
                'signalStrengthDbm' => $this->vivistarSignalDbm($rawSignal),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $wifi;
    }

    private function legacySignalStrength(?int $value): ?int
    {
        return $value === null ? null : max(0, 150 - abs($value));
    }

    private function vivistarSignalDbm(?int $value): ?int
    {
        return $value === null ? null : $value - 150;
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
