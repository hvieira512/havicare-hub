<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

/**
 * O que se configura num radar, pela API Cloud2cloud da Qinglanst. O `command` é a própria chave:
 * quem a traduz para os pedidos da cloud é o `QinglanstCloudCommands`, na entrega.
 */
final class QinglanstConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = static fn(string $key, string $input, array $fields, string $category, int $order, mixed ...$named): array
            => ConfigurationDefinition::make($key, $key, $input, $fields, [], $category, $order, ...$named);

        return [
            $entry('vital_sign_sensitivity', 'sensitivitySlider', ['sensitivity'], 'health', 10, options: ['min' => 1, 'max' => 99, 'default' => 5]),
            $entry('breath_rate_alert', 'valueRange', ['minPerMinute', 'maxPerMinute'], 'alarms', 10, options: [
                'min' => 0, 'max' => 255, 'unit' => '/min', 'default' => ['minPerMinute' => 8, 'maxPerMinute' => 24],
            ]),
            $entry('heart_rate_alert', 'valueRange', ['minBpm', 'maxBpm'], 'alarms', 20, options: [
                'min' => 0, 'max' => 255, 'unit' => 'bpm', 'default' => ['minBpm' => 50, 'maxBpm' => 90],
            ]),
            $entry('weak_vital_signs_alarm', 'switchWithValue', ['enabled', 'afterMinutes'], 'alarms', 30, options: [
                'unit' => 'min', 'min' => 1, 'max' => 60, 'default' => ['enabled' => false, 'afterMinutes' => 10],
            ]),
            $entry('fall_alarm', 'number', ['afterSeconds'], 'alarms', 40, options: [
                'min' => 10, 'max' => 300, 'step' => 10, 'unit' => 's', 'default' => 60,
            ]),
            $entry('sitting_on_floor_alarm', 'switchWithValue', ['enabled', 'afterSeconds'], 'alarms', 50, options: [
                'unit' => 's', 'min' => 10, 'max' => 300, 'step' => 10, 'default' => ['enabled' => false, 'afterSeconds' => 100],
            ]),
            $entry('working_mode', 'optionCards', ['mode'], 'system', 10, options: [
                'mode' => [
                    ['value' => 'people_tracking'],
                    ['value' => 'fall_detection'],
                    ['value' => 'sleep_monitoring'],
                    ['value' => 'full_function'],
                ],
                'default' => 'full_function',
            ]),
            $entry('radar_installation', 'radarInstallation', ['mounting', 'heightCm'], 'system', 20, options: [
                'mounting' => [['value' => 'ceiling'], ['value' => 'wall']],
                'heightCm' => ['min' => 150, 'max' => 330, 'step' => 10],
                'default' => ['mounting' => 'ceiling', 'heightCm' => 280],
            ]),
            $entry('detection_boundary', 'radarBoundary', ['leftM', 'rightM', 'frontM', 'backM'], 'system', 30, options: [
                'step' => 0.1,
                'ceiling' => ['leftM' => 3, 'rightM' => 3, 'frontM' => 2, 'backM' => 2],
                'wall' => ['leftM' => 3, 'rightM' => 3, 'frontM' => 4, 'backM' => 0],
                'default' => ['leftM' => 3, 'rightM' => 3, 'frontM' => 2, 'backM' => 2],
            ]),
            $entry('measure_boundary', 'action', ['enabled'], 'system', 40, transient: true, actions: ['on' => 'start', 'off' => 'stop']),
            $entry('radar_areas', 'radarAreas', ['items'], 'system', 50, limit: 16),
            $entry('read_device_state', 'action', [], 'system', 60, transient: true, verb: 'read'),
        ];
    }
}
