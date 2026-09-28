<?php

namespace Hub\Domain\Capability\Definition;

/**
 * As capacidades do radar nomeiam o que se mede, e não as mensagens do fabricante. A
 * frequência cardíaca e a respiratória partilham chaves e formas com as do relógio, e por
 * isso reaproveitam os mesmos cartões.
 *
 * O `sleep_state` não é o `sleep` do relógio -- aquele é um relatório, este é o estado num
 * instante -- e o `presence` não é o `location`, que é geográfico. A postura não é
 * capacidade: é de cada pessoa, e vive dentro do `presence` ao lado da posição.
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
                'reading' => [
                    'heart_rate' => 'Frequência cardíaca',
                    'breath_rate' => 'Frequência respiratória',
                    'sleep_state' => 'Estado do sono',
                    'presence' => 'Presença',
                    'position_minute_stats' => 'Estatísticas de posições por minuto',
                    'vitals_minute_stats' => 'Estatísticas de sinais vitais por minuto',
                ],
            ],
            'alarms' => [
                'event' => [
                    'fall' => 'Queda',
                    'vitals_alarm' => 'Alarme de sinais vitais',
                    'presence_event' => 'Entradas e saídas',
                ],
            ],
        ];
    }
}
