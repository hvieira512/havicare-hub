<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * Traz um anúncio W6R descodificado para as formas genéricas do hub.
 *
 * Um toque é reportado como um contador por modo e não como um evento, por isso quem chama
 * traz a contagem anterior e isto só emite um `help_call` quando o contador mexeu.
 */
final class W6rNormalizer
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
            'schemaVersion' => 2,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($device),
            'source' => array_filter([
                'protocol' => 'moko-w6r',
                'nativeType' => 'manufacturer_data',
                'gatewayId' => $gatewayId,
                'rssiDbm' => $decoded['rssiDbm'] ?? null,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''),
        ];

        return [
            'telemetry' => $this->telemetry($decoded['info'] ?? null, $common),
            'events' => $this->events($decoded['alarm'] ?? null, $previousTriggerCount, $common),
        ];
    }

    /**
     * @param array<string, mixed>|null $info
     * @param array<string, mixed> $common
     * @return array<string, array<string, mixed>>
     */
    private function telemetry(?array $info, array $common): array
    {
        if ($info === null) {
            return [];
        }

        $telemetry = [];

        if (isset($info['batteryPercent'])) {
            $telemetry['battery'] = ['type' => 'battery', 'data' => ['percent' => (int)$info['batteryPercent']]] + $common;
        } elseif (isset($info['batteryVoltageMv'])) {
            $telemetry['battery'] = ['type' => 'battery', 'data' => ['voltageMv' => (int)$info['batteryVoltageMv']]] + $common;
        }

        if (isset($info['accelerationMg']) && is_array($info['accelerationMg'])) {
            $axes = $info['accelerationMg'];
            $telemetry['motion'] = ['type' => 'motion', 'data' => [
                'xMg' => (int)$axes['x'],
                'yMg' => (int)$axes['y'],
                'zMg' => (int)$axes['z'],
                // Independente da orientação, para um número só ser comparável entre
                // portadores e posições de montagem.
                'magnitudeMg' => (int)round(sqrt(
                    ($axes['x'] ** 2) + ($axes['y'] ** 2) + ($axes['z'] ** 2)
                )),
            ]] + $common;
        }

        return $telemetry;
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

        // O contador é anunciado continuamente, e por isso sem um valor anterior não há com
        // que comparar: o primeiro avistamento estabelece a linha de base em vez de repetir
        // o histórico de toques do dispositivo.
        $triggerCount = (int)$alarm['triggerCount'];
        if ($previousTriggerCount === null || $triggerCount === $previousTriggerCount) {
            return [];
        }

        return [[
            'type' => 'help_call',
            'data' => [
                'pressType' => $alarm['pressMode'],
                'triggerCount' => $triggerCount,
                // Um dispositivo que reinicia põe os contadores a zero, e por isso uma
                // descida continua a ser um toque e não um delta negativo.
                'presses' => $triggerCount > $previousTriggerCount
                    ? $triggerCount - $previousTriggerCount
                    : 1,
            ],
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
