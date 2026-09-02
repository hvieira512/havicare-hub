<?php

namespace Hub\Domain\Capability\Definition;

/**
 * As capacidades do radar nomeiam o que se mede, e não as mensagens do fabricante. A
 * frequência cardíaca e a respiratória partilham chaves e formas com as do relógio, e por
 * isso reaproveitam os mesmos cartões.
 *
 * O `sleep_state` não é o `sleep` do relógio -- aquele é um relatório, este é o estado num
 * instante -- e o `presence` não é o `location`, que é geográfico.
 *
 * A postura não é capacidade: é de cada pessoa, e vive dentro do `presence` ao lado da
 * posição. Os dois envelopes por minuto são agregados e não leituras.
 */
final class RadarCapabilityDefinitions
{
    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'heart_rate', 'label' => 'Frequência cardíaca', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'breath_rate', 'label' => 'Frequência respiratória', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'sleep_state', 'label' => 'Estado do sono', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'presence', 'label' => 'Presença', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'position_minute_stats', 'label' => 'Estatísticas de posições por minuto', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'vitals_minute_stats', 'label' => 'Estatísticas de sinais vitais por minuto', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'alarms', 'key' => 'fall', 'label' => 'Queda', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'radar', 'section' => 'alarms', 'key' => 'vitals_alarm', 'label' => 'Alarme de sinais vitais', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'radar', 'section' => 'alarms', 'key' => 'presence_event', 'label' => 'Entradas e saídas', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ];
    }
}
