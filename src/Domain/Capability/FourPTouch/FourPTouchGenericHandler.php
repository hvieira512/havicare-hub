<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\FourPTouch;

use Hub\Domain\Capability\CapabilityHelpers;

/**
 * O recurso para as capacidades genéricas do 4P Touch que ainda não têm contrato próprio.
 */
final class FourPTouchGenericHandler
{
    use CapabilityHelpers;

    /** @param array<array-key, mixed> $desired */
    public function fromNative(string $genericKey, string $nativeKey, array $desired): mixed
    {
        if ($genericKey === 'fall_sensitivity' && $nativeKey === 'fallDownSensitivity') {
            $result = [
                'sensitivity' => (int)($desired['sensitivity'] ?? $desired['sensitivityLevel'] ?? 5),
            ];
            $totalLevels = $desired['levels'] ?? $desired['totalLevels'] ?? null;
            if ($totalLevels !== null && $totalLevels !== '') {
                $result['levels'] = (int)$totalLevels;
            }

            return $result;
        }

        return $desired;
    }

    /**
     * Converte um payload genérico no payload nativo do 4P Touch.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toNative(string $genericKey, mixed $value): array
    {
        return match ($genericKey) {
            'location_reporting_interval' => ['uploadInterval' => self::requireObjectValue($value, 'uploadInterval')],
            'monitor_number' => ['monitorNumber' => ['phone' => self::requireStringValue($value, 'phone')]],
            'language_timezone' => ['languageTimezone' => self::requireObjectValue($value, 'languageTimezone')],
            'low_battery_alert' => ['lowBatterySmsAlerts' => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'remove_watch_alarm' => ['removeWatchAlarm' => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'remove_watch_sms_alert' => ['removeWatchSmsAlerts' => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'do_not_disturb' => ['doNotDisturb' => ['ranges' => self::requireListValue(is_array($value) && array_key_exists('ranges', $value) ? $value['ranges'] : $value, 'ranges')]],
            'fall_detection' => ['fallDownAlert' => self::requireObjectValue($value, 'fallDownAlert')],
            'fall_sensitivity' => ['fallDownSensitivity' => $this->fallSensitivityPayload($value)],
            'auto_vitals_interval' => ['healthAutoMeasurement' => self::requireObjectValue($value, 'healthAutoMeasurement')],
            'sound_profile' => ['profile' => ['mode' => self::requireStringField($value, 'mode')]],
            'pedometer_schedule' => ['walkTime' => ['ranges' => self::requireListValue(is_array($value) && array_key_exists('ranges', $value) ? $value['ranges'] : $value, 'ranges')]],
            'sleep_monitoring' => ['sleepTime' => ['range' => self::requireStringField($value, 'range')]],
            'temperature_measurement_interval' => ['bodyTemperatureInterval' => self::requireObjectValue($value, 'bodyTemperatureInterval')],
            'make_call' => ['makeCall' => ['phone' => self::requireStringField($value, 'phone')]],
            'center_number' => ['centerNumber' => ['phone' => self::requireStringField($value, 'phone')]],
            'push_message' => ['pushMessage' => ['message' => self::requireStringField($value, 'message')]],
            'restart_device' => ['restartCommand' => []],
            'power_off' => ['powerOffCommand' => []],
            'find_device' => ['findDeviceCommand' => []],
            default => throw new \InvalidArgumentException("Unsupported four-p-touch capability {$genericKey}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fallSensitivityPayload(mixed $value): array
    {
        $payload = self::requireObjectValue($value, 'fallDownSensitivity');
        if (!array_key_exists('levels', $payload) && !array_key_exists('totalLevels', $payload)) {
            $payload['levels'] = 8;
        }

        return $payload;
    }
}
