<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * Traz um anúncio W6 descodificado para as formas genéricas do hub.
 *
 * Ao contrário da W6B, um toque aqui não traz contador: o slot com o Instance ID daquele
 * modo simplesmente passa a ser anunciado durante 30 segundos. O que chega a este
 * normalizador é já um toque -- quem chama estrangula por tempo para a frame repetida não
 * virar trinta alarmes.
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

        // Sem `triggerCount` nem `presses`: a frame não conta nada, só diz qual o modo. Um
        // consumidor que espere o contador da W6B vê o campo ausente em vez de um zero, que
        // seria indistinguível de "nunca foi premida".
        return [[
            'type' => 'help_call',
            'data' => ['pressType' => $pressMode],
        ] + $common];
    }

    /** @param array<string, mixed> $device @return array<string, string> */
    private function device(array $device): array
    {
        return array_filter([
            'id' => (string)$device['imei'],
            'supplier' => (string)($device['supplier'] ?? ''),
            'model' => (string)($device['model'] ?? ''),
            'commercialName' => (string)($device['commercialName'] ?? ''),
        ], static fn(string $value): bool => $value !== '');
    }
}
