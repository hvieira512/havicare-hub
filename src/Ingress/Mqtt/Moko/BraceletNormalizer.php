<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Device\TelemetryEnvelope;

/**
 * Traz um anúncio W6 ou W6B descodificado para as formas genéricas do hub. As duas descodificam
 * para o mesmo `info`; o toque é que difere. A W6B conta-os por modo, e só sai `help_call` quando
 * o contador mexe; a W6 não conta, e o que chega aqui já é um toque estrangulado por tempo.
 */
final class BraceletNormalizer
{
    /** A frame de cada protocolo. */
    private const NATIVE_TYPES = ['moko-w6' => 'service_data', 'moko-w6b' => 'manufacturer_data'];

    /** Os modos que representam alguém a premir o botão; a inactividade da W6B não é um. */
    private const HELP_CALL_MODES = ['single', 'double', 'triple', 'long'];

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @param 'moko-w6'|'moko-w6b' $protocol
     * @param int|null $previousTriggerCount da W6B; null enquanto se estabelece a referência
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function normalize(
        array $decoded,
        array $device,
        string $gatewayId,
        string $protocol,
        ?int $previousTriggerCount = null,
    ): array {
        $rssiDbm = is_int($decoded['rssiDbm'] ?? null) ? $decoded['rssiDbm'] : null;
        $envelope = static fn (string $type, array $data): array => TelemetryEnvelope::for(
            $type,
            (string)$device['imei'],
            $device,
            $protocol,
            self::NATIVE_TYPES[$protocol],
            $data,
            $gatewayId,
            $rssiDbm,
        );

        return [
            'telemetry' => self::telemetry($decoded['info'] ?? null, $envelope),
            'events' => self::events($decoded['alarm'] ?? null, $previousTriggerCount, $envelope),
        ];
    }

    /**
     * @param array<string, mixed>|null $info
     * @param \Closure(string, array<string, mixed>): array<string, mixed> $envelope
     * @return array<string, array<string, mixed>>
     */
    private static function telemetry(?array $info, \Closure $envelope): array
    {
        $telemetry = [];

        if (isset($info['batteryPercent'])) {
            $telemetry['battery'] = $envelope('battery', ['percent' => (int)$info['batteryPercent']]);
        } elseif (isset($info['batteryVoltageMv'])) {
            $telemetry['battery'] = $envelope('battery', ['voltageMv' => (int)$info['batteryVoltageMv']]);
        }

        if (isset($info['accelerationMg']) && is_array($info['accelerationMg'])) {
            $axes = $info['accelerationMg'];
            $telemetry['motion'] = $envelope('motion', [
                'xMg' => (int)$axes['x'],
                'yMg' => (int)$axes['y'],
                'zMg' => (int)$axes['z'],
                // Independente da orientação, para um número só ser comparável entre
                // portadores e posições de montagem.
                'magnitudeMg' => (int)round(sqrt(($axes['x'] ** 2) + ($axes['y'] ** 2) + ($axes['z'] ** 2))),
            ]);
        }

        return $telemetry;
    }

    /**
     * @param array<string, mixed>|null $alarm
     * @param \Closure(string, array<string, mixed>): array<string, mixed> $envelope
     * @return list<array<string, mixed>>
     */
    private static function events(?array $alarm, ?int $previousTriggerCount, \Closure $envelope): array
    {
        $pressMode = (string)($alarm['pressMode'] ?? '');
        if (!in_array($pressMode, self::HELP_CALL_MODES, true)) {
            return [];
        }

        // Sem contador, `triggerCount` e `presses` não saem: um zero leria-se como nunca premida.
        if (!isset($alarm['triggerCount'])) {
            return [$envelope('help_call', ['pressType' => $pressMode])];
        }

        // O primeiro avistamento só estabelece a linha de base do contador.
        $triggerCount = (int)$alarm['triggerCount'];
        if ($previousTriggerCount === null || $triggerCount === $previousTriggerCount) {
            return [];
        }

        return [$envelope('help_call', [
            'pressType' => $pressMode,
            'triggerCount' => $triggerCount,
            // Um reinício põe os contadores a zero: uma descida também é toque.
            'presses' => $triggerCount > $previousTriggerCount ? $triggerCount - $previousTriggerCount : 1,
        ])];
    }
}
