<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\Device\Decoder\FourPTouchEventDecoder;
use Hub\Device\Decoder\PillDispenserEventDecoder;
use Hub\Device\Decoder\VivistarEventDecoder;
use Hub\Device\Decoder\WonlexEventDecoder;

final class DeviceEventDecoder
{
    /**
     * @param array<string, mixed> $decoded
     * @return list<array{feature: string, nativeType: string, value: array<string, mixed>, extra?: array<string, mixed>}>
     */
    public function decode(DeviceSession $session, array $decoded): array
    {
        $nativeType = (string)($decoded['type'] ?? '');
        if ($nativeType === '' || $nativeType === 'login') {
            return [];
        }

        $payload = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        if ($session->protocol === 'wonlex-json' && $nativeType === 'upSleep') {
            foreach (['isAccumulative', 'IsAccumulative'] as $field) {
                if (array_key_exists($field, $decoded) && !array_key_exists($field, $payload)) {
                    $payload[$field] = $decoded[$field];
                }
            }
        }

        $events = match ($session->protocol) {
            'wonlex-json' => WonlexEventDecoder::decode($nativeType, $payload),
            'vivistar-iw' => VivistarEventDecoder::decode($nativeType, $payload),
            'four-p-touch' => FourPTouchEventDecoder::decode($nativeType, $payload),
            'zayata-m228' => PillDispenserEventDecoder::decode($nativeType, $payload),
            default => [],
        };

        return array_values(array_filter($events, 'is_array'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{feature: string, nativeType: string, value: array<string, mixed>}|null
     */
    public static function event(string $feature, string $nativeType, array $payload): ?array
    {
        $value = FeatureNormalizer::normalize($feature, $payload);
        if ($value === []) {
            return null;
        }

        return array_filter([
            'feature' => $feature,
            'nativeType' => $nativeType,
            'value' => $value,
        ], static fn (mixed $field): bool => $field !== []);
    }

    /**
     * Um evento por alarme ativo — vários bits da máscara do 4P Touch dão vários eventos;
     * máscara a zero não dá nenhum.
     *
     * @param array<string, mixed> $payload
     * @return list<array{feature: string, nativeType: string, value: array<string, mixed>}>
     */
    public static function alarmEvents(string $nativeType, array $payload): array
    {
        return array_map(
            static fn (array $alarm): array => $alarm + ['nativeType' => $nativeType],
            FeatureNormalizer::alarms($payload)
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{feature: string, nativeType: string, value: array<string, mixed>}|null
     */
    public static function locationEvent(string $nativeType, array $payload): ?array
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
            'upLocation' => match ((string)($payload['positionDataType'] ?? $payload['dataType'] ?? $payload['DataType'] ?? '')) {
                '0' => 'periodic',
                '1' => 'requested',
                default => null,
            },
            default => null,
        };

        return self::event('location', $nativeType, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{feature: string, nativeType: string, value: array<string, mixed>}|null
     */
    public static function heartRateFromBloodPressure(string $nativeType, array $payload): ?array
    {
        $pulse = $payload['pulse'] ?? $payload['pulseBpm'] ?? $payload['heartRate'] ?? $payload['hr'] ?? null;
        if ($pulse === null) {
            $rawData = $payload['data'] ?? $payload['date'] ?? null;
            if (is_string($rawData) && str_contains($rawData, '/')) {
                $parts = preg_split('/[\/,\-]+/', $rawData) ?: [];
                $pulse = $parts[2] ?? null;
            }
        }

        return self::event('heart_rate', $nativeType, [
            'pulse' => $pulse,
        ]);
    }

    public static function stringField(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }
}
