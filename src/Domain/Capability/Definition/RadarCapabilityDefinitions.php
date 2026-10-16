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
                    'heart_rate' => 'Frequência cardíaca',
                    'breath_rate' => 'Frequência respiratória',
                    'sleep_state' => 'Estado do sono',
                    'presence' => 'Presença',
                    'position_minute_stats' => 'Estatísticas de posições por minuto',
                    'vitals_minute_stats' => 'Estatísticas de sinais vitais por minuto',
                    // As três chegam quando alguém lê o radar, e não sozinhas.
                    'connectivity' => 'Conectividade',
                    'tilt' => 'Inclinação',
                    'firmware_version' => 'Versão do firmware',
                ],
            ],
            'health' => [
                'setting' => [
                    'vital_sign_sensitivity' => 'Sensibilidade dos sinais vitais',
                ],
            ],
            'alarms' => [
                'setting' => [
                    'breath_rate_alert' => 'Alerta de frequência respiratória',
                    'heart_rate_alert' => 'Alerta de frequência cardíaca',
                    'weak_vital_signs_alarm' => 'Sinais vitais fracos',
                    'fall_alarm' => 'Queda',
                    'sitting_on_floor_alarm' => 'Sentado no chão',
                ],
                'event' => [
                    'fall' => 'Queda',
                    'heart_rate_high' => 'Frequência cardíaca alta',
                    'heart_rate_low' => 'Frequência cardíaca baixa',
                    'breath_rate_high' => 'Frequência respiratória alta',
                    'breath_rate_low' => 'Frequência respiratória baixa',
                    'apnea' => 'Apneia',
                    'weak_vital_signs' => 'Sinais vitais fracos',
                    'zone_entry' => 'Entrada numa zona',
                    'zone_exit' => 'Saída de uma zona',
                ],
            ],
            'settings_system' => [
                'setting' => [
                    'working_mode' => 'Modo de funcionamento',
                    'radar_installation' => 'Montagem e altura',
                    'detection_boundary' => 'Limites de deteção',
                    'radar_areas' => 'Áreas',
                ],
                'action' => [
                    'measure_boundary' => 'Medir os limites',
                    'read_device_state' => 'Ler do radar',
                ],
            ],
        ];
    }
}
