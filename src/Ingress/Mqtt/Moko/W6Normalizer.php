<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Device\DeviceDescriptor;

/**
 * Traz um anúncio W6 descodificado para as formas genéricas do hub. O que chega aqui já é um
 * toque: quem chama estrangula por tempo a frame repetida.
 */
final class W6Normalizer
{
    /** Os modos que representam alguém a premir o botão. */
    private const HELP_CALL_MODES = ['single', 'double', 'triple'];

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function normalize(array $decoded, array $device, string $gatewayId): array
    {
        $common = [
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($device),
            'source' => array_filter([
                'protocol' => 'moko-w6',
                'nativeType' => 'service_data',
                'gatewayId' => $gatewayId,
                'rssiDbm' => $decoded['rssiDbm'] ?? null,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''),
        ];

        return [
            'telemetry' => BraceletTelemetry::from($decoded['info'] ?? null, $common),
            'events' => $this->events($decoded['alarm'] ?? null, $common),
        ];
    }

    /**
     * @param array<string, mixed>|null $alarm
     * @param array<string, mixed> $common
     * @return list<array<string, mixed>>
     */
    private function events(?array $alarm, array $common): array
    {
        $pressMode = (string)($alarm['pressMode'] ?? '');
        if (!in_array($pressMode, self::HELP_CALL_MODES, true)) {
            return [];
        }

        // Sem `triggerCount` nem `presses`: a frame não conta, e um zero leria-se como nunca premida.
        return [[
            'type' => 'help_call',
            'data' => ['pressType' => $pressMode],
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
