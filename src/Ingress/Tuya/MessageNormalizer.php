<?php

declare(strict_types=1);

namespace Hub\Ingress\Tuya;

use Hub\Device\DeviceDescriptor;

/**
 * Os pontos de dados da pulseira SOS Wi-Fi nas formas do hub. A categoria `sos` da Tuya só tem
 * dois neste produto: o `sos_state` e o `battery_percentage`.
 */
final class MessageNormalizer
{
    /**
     * O `deviceOffline` fica de fora: chega minutos depois de cada acordar, e quem dá a pulseira
     * por desligada é o prazo próprio dela. O resto vem da cloud, e não do aparelho.
     *
     * @param array<string, mixed> $business
     */
    public static function isFromDevice(array $business): bool
    {
        return in_array($business['bizCode'] ?? null, ['devicePropertyMessage', 'deviceOnline'], true);
    }

    /**
     * @param array<string, mixed> $business uma mensagem do aparelho, já decifrada
     * @param array<string, mixed> $device o dispositivo como a whitelist o resolve
     * @return array{raw: array<string, mixed>, telemetry: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function normalize(array $business, array $device): array
    {
        $properties = ($business['bizCode'] ?? null) === 'devicePropertyMessage'
            ? (array)($business['bizData']['properties'] ?? [])
            : [];

        $descriptor = DeviceDescriptor::of((string)$device['imei'], $device);
        $normalized = [
            'raw' => [
                'direction' => 'uplink',
                'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'device' => $descriptor,
                'debug' => ['protocol' => 'tuya-cloud', 'transport' => 'pulsar', 'payload' => $business],
            ],
            'telemetry' => [],
            'events' => [],
        ];

        foreach ($properties as $property) {
            $code = (string)($property['code'] ?? '');
            $value = $property['value'] ?? null;
            $time = (int)($property['time'] ?? 0);
            $common = [
                'occurredAt' => gmdate('Y-m-d\TH:i:s\Z', $time > 0 ? intdiv($time, 1000) : time()),
                'device' => $descriptor,
                'source' => ['protocol' => 'tuya-cloud', 'nativeType' => $code],
            ];

            // O `sos_state` a falso é o botão a voltar sozinho ao repouso, e não um cancelamento.
            if ($code === 'sos_state' && $value === true) {
                $normalized['events'][] = ['type' => 'help_call'] + $common + ['data' => []];
            } elseif ($code === 'battery_percentage' && is_numeric($value)) {
                $normalized['telemetry'][] = ['type' => 'battery'] + $common + ['data' => ['percent' => (int)$value]];
            }
        }

        return $normalized;
    }
}
