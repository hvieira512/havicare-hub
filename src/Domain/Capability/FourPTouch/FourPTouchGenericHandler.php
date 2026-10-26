<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\FourPTouch;

use Hub\Command\DeviceConfigurationCatalog;
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
        // O nome nativo é o da definição; aqui fica só a forma do valor de cada capacidade.
        $native = trim((string)(DeviceConfigurationCatalog::entryForCapability('four-p-touch', $genericKey)['key'] ?? $genericKey));

        return match ($genericKey) {
            'location_reporting_interval' => [$native => self::requireObjectValue($value, $native)],
            'monitor_number' => [$native => ['phone' => self::requireStringValue($value, 'phone')]],
            'language_timezone' => [$native => self::requireObjectValue($value, $native)],
            'low_battery_alert' => [$native => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'remove_watch_alarm' => [$native => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'remove_watch_sms_alert' => [$native => ['enabled' => self::requireBoolLikeValue($value, 'enabled')]],
            'do_not_disturb' => [$native => ['ranges' => self::requireListValue(is_array($value) && array_key_exists('ranges', $value) ? $value['ranges'] : $value, 'ranges')]],
            'fall_detection' => [$native => self::requireObjectValue($value, $native)],
            'fall_sensitivity' => [$native => $this->fallSensitivityPayload($value)],
            'auto_vitals_interval' => [$native => self::requireObjectValue($value, $native)],
            'sound_profile' => [$native => ['mode' => self::requireStringField($value, 'mode')]],
            'pedometer_schedule' => [$native => ['ranges' => self::requireListValue(is_array($value) && array_key_exists('ranges', $value) ? $value['ranges'] : $value, 'ranges')]],
            'sleep_monitoring' => [$native => ['range' => self::requireStringField($value, 'range')]],
            'temperature_measurement_interval' => [$native => self::requireObjectValue($value, $native)],
            'make_call' => [$native => ['phone' => self::requireStringField($value, 'phone')]],
            'center_number' => [$native => ['phone' => self::requireStringField($value, 'phone')]],
            'push_message' => [$native => ['message' => self::requireStringField($value, 'message')]],
            'restart_device' => [$native => []],
            'power_off' => [$native => []],
            'find_device' => [$native => []],
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
