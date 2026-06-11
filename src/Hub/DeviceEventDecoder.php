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
            'upLocation' => [$this->event('location', $nativeType, $payload)],
            'upStep', 'upKcal', 'upDistance' => [$this->event('activity', $nativeType, $payload)],
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
            default => [],
        };
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
}
