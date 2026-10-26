<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Payload;

/**
 * Valida o que se configura num radar, com os limites da API Cloud2cloud. Os pedidos à cloud
 * monta-os o `QinglanstCloudCommands` na entrega, sobre o que o radar tem nesse momento.
 */
final class QinglanstPayloadBuilder extends ConfigurationPayloadBuilder
{
    public const MODES = ['full_function', 'fall_detection', 'people_tracking', 'sleep_monitoring'];

    public const MOUNTINGS = ['ceiling', 'wall'];

    /** O `coordKey` vai de 0 a 15: o radar guarda 16 áreas. */
    public const MAX_AREAS = 16;

    /** Os tipos de área que a API aceita, os do `QinglanstBridge::AREA_TYPES` sem a mobília; apagar é da entrega. */
    public const AREA_TYPES = ['custom', 'bed', 'interference', 'door', 'monitoring_bed', 'alarm_area'];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function build(string $key, array $payload): array
    {
        return match ($key) {
            'vital_sign_sensitivity' => ['sensitivity' => self::intInRange($payload['sensitivity'] ?? null, 'sensitivity', 1, 99)],
            'breath_rate_alert' => self::range($payload, 'minPerMinute', 'maxPerMinute'),
            'heart_rate_alert' => self::range($payload, 'minBpm', 'maxBpm'),
            'weak_vital_signs_alarm' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? null, 'enabled'),
                'afterMinutes' => self::intInRange($payload['afterMinutes'] ?? null, 'afterMinutes', 1, 60),
            ],
            'fall_alarm' => ['afterSeconds' => self::tensOfSeconds($payload['afterSeconds'] ?? null, 'afterSeconds')],
            'sitting_on_floor_alarm' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? null, 'enabled'),
                'afterSeconds' => self::tensOfSeconds($payload['afterSeconds'] ?? null, 'afterSeconds'),
            ],
            'working_mode' => ['mode' => self::oneOf($payload['mode'] ?? null, self::MODES, 'mode')],
            'radar_installation' => [
                'mounting' => self::oneOf($payload['mounting'] ?? null, self::MOUNTINGS, 'mounting'),
                'heightCm' => self::decimetres($payload['heightCm'] ?? null),
            ],
            'detection_boundary' => [
                'leftM' => self::metres($payload['leftM'] ?? null, 3.0, 'leftM'),
                'rightM' => self::metres($payload['rightM'] ?? null, 3.0, 'rightM'),
                'frontM' => self::metres($payload['frontM'] ?? null, 4.0, 'frontM'),
                'backM' => self::metres($payload['backM'] ?? null, 2.0, 'backM'),
            ],
            'radar_areas' => ['items' => self::areas($payload['items'] ?? null)],
            'measure_boundary' => ['enabled' => (bool)self::boolInt($payload['enabled'] ?? null, 'enabled')],
            'read_device_state' => [],
            default => throw new \InvalidArgumentException("Unsupported qinglanst-radar configuration {$key}"),
        };
    }

    /**
     * O baixo abaixo do alto, como a cloud exige.
     *
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private static function range(array $payload, string $minField, string $maxField): array
    {
        $min = self::intInRange($payload[$minField] ?? null, $minField, 0, 255);
        $max = self::intInRange($payload[$maxField] ?? null, $maxField, 0, 255);
        if ($min >= $max) {
            throw new \InvalidArgumentException("{$minField} must be below {$maxField}");
        }

        return [$minField => $min, $maxField => $max];
    }

    /** O radar conta em blocos de 10 s, de 10 s a 5 minutos. */
    private static function tensOfSeconds(mixed $value, string $field): int
    {
        $seconds = self::intInRange($value, $field, 10, 300);
        if ($seconds % 10 !== 0) {
            throw new \InvalidArgumentException("{$field} must be a multiple of 10");
        }

        return $seconds;
    }

    /** A altura vai em decímetros, de 1,5 a 3,3 m. */
    private static function decimetres(mixed $value): int
    {
        $centimetres = self::intInRange($value, 'heightCm', 150, 330);
        if ($centimetres % 10 !== 0) {
            throw new \InvalidArgumentException('heightCm must be a multiple of 10');
        }

        return $centimetres;
    }

    /** Metros com uma casa, porque a cloud recebe decímetros. */
    private static function metres(mixed $value, float $max, string $field): float
    {
        if (!is_numeric($value) || (float)$value < 0 || (float)$value > $max) {
            throw new \InvalidArgumentException("{$field} must be between 0 and {$max}");
        }

        return round((float)$value, 1);
    }

    /**
     * @param list<string> $choices
     */
    private static function oneOf(mixed $value, array $choices, string $field): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new \InvalidArgumentException("{$field} must be one of " . implode(', ', $choices));
        }

        return $value;
    }

    /**
     * Cada área com o número que a telemetria usa como `areaId`, e a caixa em decímetros.
     *
     * @return list<array{key: int, type: string, name: string, xMinDm: int, yMinDm: int, xMaxDm: int, yMaxDm: int}>
     */
    private static function areas(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_AREAS) {
            throw new \InvalidArgumentException('items must be a list of at most ' . self::MAX_AREAS . ' areas');
        }

        $areas = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("items[{$index}] must be an object");
            }
            $key = self::intInRange($item['key'] ?? null, "items[{$index}].key", 0, self::MAX_AREAS - 1);
            if (isset($areas[$key])) {
                throw new \InvalidArgumentException("items[{$index}].key {$key} is repeated");
            }
            $type = $item['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::AREA_TYPES, true)) {
                throw new \InvalidArgumentException("items[{$index}].type must be one of " . implode(', ', self::AREA_TYPES));
            }
            $box = [];
            foreach (['xMinDm', 'yMinDm', 'xMaxDm', 'yMaxDm'] as $field) {
                $box[$field] = self::intInRange($item[$field] ?? null, "items[{$index}].{$field}", -127, 127);
            }
            if ($box['xMinDm'] >= $box['xMaxDm'] || $box['yMinDm'] >= $box['yMaxDm']) {
                throw new \InvalidArgumentException("items[{$index}] must have a size");
            }
            $name = trim((string)($item['name'] ?? ''));
            $areas[$key] = ['key' => $key, 'type' => $type, 'name' => mb_substr($name, 0, 64)] + $box;
        }
        ksort($areas);

        return array_values($areas);
    }
}
