<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * A bateria e o movimento de uma pulseira MOKO nas formas genéricas do hub. A W6 e a W6B
 * descodificam para o mesmo `info`, e daí em diante partilham isto.
 */
final class BraceletTelemetry
{
    /**
     * @param array<string, mixed>|null $info
     * @param array<string, mixed> $common campos comuns a toda a telemetria do avistamento
     * @return array<string, array<string, mixed>>
     */
    public static function from(?array $info, array $common): array
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
}
