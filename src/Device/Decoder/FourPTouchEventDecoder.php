<?php

namespace Hub\Device\Decoder;

use Hub\Device\DeviceEventDecoder;
use Hub\Protocol\Adapter\FourPTouchAdapter;

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
            // O `TS` não publica nada. Dos dezasseis campos que devolve, onze são o hub a ler
            // de volta o que escreveu — idioma, fuso, intervalo, perfil, o endereço do nosso
            // servidor, a identidade, a bateria que já chega no heartbeat. Saíam todos numa
            // string só, num campo chamado `deviceTime`. Pedir o estado é uma acção, e a
            // trama fica no fluxo cru para quem precisar de a ler.
            default => [],
        };
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
