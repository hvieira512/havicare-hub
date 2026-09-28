<?php

namespace Hub\Device\Decoder;

use Hub\Device\DeviceEventDecoder;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Support\Values;

final class FourPTouchEventDecoder
{
    public static function decode(string $nativeType, array $payload): array
    {
        return match (true) {
            $nativeType === 'LK' => array_values(array_filter([
                DeviceEventDecoder::event('heartbeat', $nativeType, $payload),
                DeviceEventDecoder::event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
                DeviceEventDecoder::event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $nativeType === 'bphrt' => [
                DeviceEventDecoder::event('blood_pressure', $nativeType, $payload),
                DeviceEventDecoder::event('heart_rate', $nativeType, $payload),
            ],
            $nativeType === 'oxygen' => [
                DeviceEventDecoder::event('blood_oxygen', $nativeType, $payload),
            ],
            $nativeType === 'btemp2' => [
                DeviceEventDecoder::event('temperature', $nativeType, $payload),
            ],
            self::isPosition($nativeType) => array_values(array_filter([
                DeviceEventDecoder::locationEvent($nativeType, $payload),
                DeviceEventDecoder::event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
                DeviceEventDecoder::event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            self::isAlarm($nativeType) => array_values(array_filter([
                DeviceEventDecoder::locationEvent($nativeType, $payload),
                ...DeviceEventDecoder::alarmEvents($nativeType, $payload),
                DeviceEventDecoder::event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $nativeType === 'CONFIG', $nativeType === 'TAKEPILLS' => [DeviceEventDecoder::event('device_config', $nativeType, $payload)],
            $nativeType === 'VERNO' => [DeviceEventDecoder::event('firmware_version', $nativeType, $payload)],
            $nativeType === 'TS' => self::deviceStatus($nativeType, $payload),
            default => [],
        };
    }

    /**
     * A resposta ao `TS`, repartida pelas três naturezas que traz.
     *
     * Os modelos que ecoam o comando não trazem bloco nenhum, e daqui não sai nada.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function deviceStatus(string $nativeType, array $payload): array
    {
        $events = array_values(array_filter([
            DeviceEventDecoder::event('firmware_version', $nativeType, ['firmware' => $payload['firmware'] ?? null]),
            DeviceEventDecoder::event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
        ]));

        if (isset($payload['cellularEnabled']) || isset($payload['wifiConnected'])) {
            $events[] = ['feature' => 'connectivity', 'nativeType' => $nativeType, 'value' => Values::withoutNulls([
                // O que está a servir a ligação, e não o que está ligado: o rádio pode estar
                // aceso sem estar associado a rede nenhuma.
                'interface' => ($payload['wifiConnected'] ?? false) === true ? 'wifi' : 'cellular',
                'wifiEnabled' => $payload['wifiEnabled'] ?? null,
                'wifiConnected' => $payload['wifiConnected'] ?? null,
                'cellularEnabled' => $payload['cellularEnabled'] ?? null,
            ])];
        }

        $settings = Values::withoutNulls([
            'language_timezone' => isset($payload['language'], $payload['timeZone'])
                ? ['language' => $payload['language'], 'timeZone' => $payload['timeZone']]
                : null,
            'sound_profile' => isset($payload['soundProfile']) ? ['mode' => $payload['soundProfile']] : null,
            'location_reporting_interval' => isset($payload['uploadIntervalSeconds'])
                ? ['intervalSeconds' => $payload['uploadIntervalSeconds']]
                : null,
            // Não tem capacidade e não ganha uma: entra só para se ver ao lado das outras.
            'heartbeat_interval' => isset($payload['heartbeatIntervalSeconds'])
                ? ['seconds' => $payload['heartbeatIntervalSeconds']]
                : null,
        ]);

        if ($settings !== []) {
            $events[] = ['feature' => 'device_config', 'nativeType' => $nativeType, 'value' => ['settings' => $settings]];
        }

        return $events;
    }

    private static function isPosition(string $nativeType): bool
    {
        return in_array($nativeType, FourPTouchAdapter::LOCATION_FRAME_TYPES, true);
    }

    private static function isAlarm(string $nativeType): bool
    {
        return in_array($nativeType, FourPTouchAdapter::ALARM_FRAME_TYPES, true);
    }
}
