<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * O envelope de uma leitura normalizada, tal como sai no MQTT. É contrato público, e o par do
 * `RawPayload`, que faz o mesmo para o `status` e o `event`.
 */
final class TelemetryEnvelope
{
    /**
     * @param array<string, mixed> $device o dispositivo como a whitelist o resolve
     * @param array<string, mixed> $data os campos da leitura, já com os nomes do hub
     *
     * @return array<string, mixed>
     */
    public static function for(
        string $type,
        string $deviceKey,
        array $device,
        string $protocol,
        string $nativeType,
        array $data,
        string $gatewayId = '',
        ?int $rssiDbm = null,
    ): array {
        $source = ['protocol' => $protocol, 'nativeType' => $nativeType];
        // O gateway só entra quando existe: um relógio fala directamente.
        if ($gatewayId !== '') {
            $source['gatewayId'] = $gatewayId;
        }
        if ($rssiDbm !== null) {
            $source['rssiDbm'] = $rssiDbm;
        }

        return [
            'type' => $type,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => DeviceDescriptor::of($deviceKey, $device),
            'source' => $source,
            'data' => $data,
        ];
    }

    /**
     * A mesma telemetria, sem o que não cabe num histórico de consulta: o traçado de um ECG
     * só sai pelo MQTT, e a dashboard guarda quantas amostras são.
     *
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    public static function forDashboard(array $telemetry): array
    {
        $samples = $telemetry['data']['samples'] ?? null;
        if (!is_array($samples)) {
            return $telemetry;
        }

        $telemetry['data']['sampleCount'] = count($samples);
        unset($telemetry['data']['samples']);

        return $telemetry;
    }
}
