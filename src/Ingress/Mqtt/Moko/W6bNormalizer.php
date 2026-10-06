<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Device\DeviceDescriptor;

/**
 * Traz um anúncio W6B descodificado para o hub. O toque é um contador por modo: com a contagem
 * anterior, só sai `help_call` quando ele mexe.
 */
final class W6bNormalizer
{
    /** Os modos que representam alguém a premir o botão. */
    private const HELP_CALL_MODES = ['single', 'double', 'long'];

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @param int|null $previousTriggerCount null while establishing the baseline
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function normalize(
        array $decoded,
        array $device,
        string $gatewayId,
        ?int $previousTriggerCount = null,
    ): array {
        $common = [
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($device),
            'source' => array_filter([
                'protocol' => 'moko-w6b',
                'nativeType' => 'manufacturer_data',
                'gatewayId' => $gatewayId,
                'rssiDbm' => $decoded['rssiDbm'] ?? null,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''),
        ];

        return [
            'telemetry' => BraceletTelemetry::from($decoded['info'] ?? null, $common),
            'events' => $this->events($decoded['alarm'] ?? null, $previousTriggerCount, $common),
        ];
    }

    /**
     * @param array<string, mixed>|null $alarm
     * @param array<string, mixed> $common
     * @return list<array<string, mixed>>
     */
    private function events(?array $alarm, ?int $previousTriggerCount, array $common): array
    {
        if ($alarm === null || !in_array($alarm['pressMode'], self::HELP_CALL_MODES, true)) {
            return [];
        }

        // O primeiro avistamento só estabelece a linha de base do contador.
        $triggerCount = (int)$alarm['triggerCount'];
        if ($previousTriggerCount === null || $triggerCount === $previousTriggerCount) {
            return [];
        }

        return [[
            'type' => 'help_call',
            'data' => [
                'pressType' => $alarm['pressMode'],
                'triggerCount' => $triggerCount,
                // Um reinício põe os contadores a zero: uma descida também é toque.
                'presses' => $triggerCount > $previousTriggerCount
                    ? $triggerCount - $previousTriggerCount
                    : 1,
            ],
        ] + $common];
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, string>
     */
    private function device(array $device): array
    {
        return DeviceDescriptor::of((string)$device['imei'], $device);
    }
}
