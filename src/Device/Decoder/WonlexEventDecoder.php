<?php

namespace Hub\Device\Decoder;

use Hub\Device\DeviceEventDecoder;

final class WonlexEventDecoder
{
    public static function decode(string $nativeType, array $payload): array
    {
        return match ($nativeType) {
            'upHeartRate' => [DeviceEventDecoder::event('heart_rate', $nativeType, $payload)],
            'upBO' => [DeviceEventDecoder::event('blood_oxygen', $nativeType, $payload)],
            'upBP' => array_values(array_filter([
                DeviceEventDecoder::event('blood_pressure', $nativeType, $payload),
                DeviceEventDecoder::heartRateFromBloodPressure($nativeType, $payload),
            ])),
            'upBS' => [DeviceEventDecoder::event('blood_sugar', $nativeType, $payload)],
            'upBodyTemperature' => [DeviceEventDecoder::event('temperature', $nativeType, $payload)],
            'upBreathe' => [DeviceEventDecoder::event('breath_rate', $nativeType, $payload)],
            'upECG' => [DeviceEventDecoder::event('ecg', $nativeType, $payload)],
            'upHRV' => [DeviceEventDecoder::event('hrv', $nativeType, $payload)],
            'upPPG' => [DeviceEventDecoder::event('ppg', $nativeType, $payload)],
            'upRR' => [DeviceEventDecoder::event('rr_interval', $nativeType, $payload)],
            'upBattery' => [DeviceEventDecoder::event('battery', $nativeType, $payload)],
            'heartbeat' => array_values(array_filter([
                DeviceEventDecoder::event('heartbeat', $nativeType, $payload),
                DeviceEventDecoder::event('battery', $nativeType, $payload),
            ])),
            'upLocation' => [DeviceEventDecoder::locationEvent($nativeType, $payload)],
            'upStep', 'upKcal', 'upDistance', 'upTodayActivity', 'upRun', 'upWalk' => [DeviceEventDecoder::event('activity', $nativeType, $payload)],
            'upSleep' => [DeviceEventDecoder::event('sleep', $nativeType, $payload)],
            'upDeviceConfig' => [DeviceEventDecoder::event('device_config', $nativeType, $payload)],
            'upShutdown' => [DeviceEventDecoder::event('device_state', $nativeType, ['state' => 'shutdown'] + $payload)],
            'upReset' => [DeviceEventDecoder::event('device_state', $nativeType, ['state' => 'factory_reset'] + $payload)],
            'upBatch' => self::decodeBatch($nativeType, $payload),
            default => [],
        };
    }

    private static function decodeBatch(string $nativeType, array $payload): array
    {
        $dataType = trim((string)($payload['dataType'] ?? ''));
        $data = trim((string)($payload['data'] ?? ''));
        if ($dataType === '' && (isset($payload['heartRate']) || isset($payload['bp']) || isset($payload['bo']))) {
            $events = [];
            if (isset($payload['heartRate'])) {
                $events[] = DeviceEventDecoder::event('heart_rate', $nativeType, $payload);
            }
            if (isset($payload['bp']) && is_string($payload['bp'])) {
                $events[] = DeviceEventDecoder::event('blood_pressure', $nativeType, ['data' => $payload['bp']]);
                $events[] = DeviceEventDecoder::heartRateFromBloodPressure($nativeType, ['data' => $payload['bp']]);
            }
            if (isset($payload['bo'])) {
                $events[] = DeviceEventDecoder::event('blood_oxygen', $nativeType, ['spo2' => $payload['bo']]);
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
            if ($dataType === 'upHeartRate') {
                $events[] = DeviceEventDecoder::event('heart_rate', $nativeType, $sample);
            } elseif ($dataType === 'upBP') {
                $events[] = DeviceEventDecoder::event('blood_pressure', $nativeType, $sample);
                $events[] = DeviceEventDecoder::heartRateFromBloodPressure($nativeType, $sample);
            } elseif ($dataType === 'upBO') {
                $events[] = DeviceEventDecoder::event('blood_oxygen', $nativeType, $sample);
            } elseif ($dataType === 'upBodyTemperature') {
                $events[] = DeviceEventDecoder::event('temperature', $nativeType, $sample);
            } elseif ($dataType === 'upBreathe') {
                $events[] = DeviceEventDecoder::event('breath_rate', $nativeType, $sample);
            }
        }

        return array_values(array_filter($events, 'is_array'));
    }
}
