<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

/**
 * As capacidades do radar nomeiam o que se mede, e as frequências partilham chaves e cartões com
 * o relógio. O `sleep_state` é um instante e não o relatório `sleep`; a postura vive no `presence`.
 */
final class RadarCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'radar';
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                'measurement' => [
                    'heart_rate',
                    'breath_rate',
                    'sleep_state',
                    'presence',
                    'position_minute_stats',
                    'vitals_minute_stats',
                    // As três chegam quando alguém lê o radar, e não sozinhas.
                    'connectivity',
                    'tilt',
                    'firmware_version',
                ],
            ],
            'health' => [
                'setting' => [
                    'vital_sign_sensitivity',
                ],
            ],
            'alarms' => [
                'setting' => [
                    'breath_rate_alert',
                    'heart_rate_alert',
                    'weak_vital_signs_alarm',
                    'fall_alarm',
                    'sitting_on_floor_alarm',
                ],
                'event' => [
                    'fall',
                    'heart_rate_high',
                    'heart_rate_low',
                    'breath_rate_high',
                    'breath_rate_low',
                    'apnea',
                    'weak_vital_signs',
                    'zone_entry',
                    'zone_exit',
                ],
            ],
            'settings_system' => [
                'setting' => [
                    'working_mode',
                    'radar_installation',
                    'detection_boundary',
                    'radar_areas',
                ],
                'action' => [
                    'measure_boundary',
                    'read_device_state',
                ],
            ],
        ];
    }
}
