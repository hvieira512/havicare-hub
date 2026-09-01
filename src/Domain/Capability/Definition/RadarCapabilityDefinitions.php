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
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'heart_rate', 'label' => 'Frequência cardíaca', 'sortOrder' => 10, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'breath_rate', 'label' => 'Frequência respiratória', 'sortOrder' => 20, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'sleep_state', 'label' => 'Estado do sono', 'sortOrder' => 30, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'presence', 'label' => 'Presença', 'sortOrder' => 40, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'position_minute_stats', 'label' => 'Estatísticas de posições por minuto', 'sortOrder' => 60, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'vitals_minute_stats', 'label' => 'Estatísticas de sinais vitais por minuto', 'sortOrder' => 70, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'alarms', 'key' => 'fall', 'label' => 'Queda', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'radar', 'section' => 'alarms', 'key' => 'vitals_alarm', 'label' => 'Alarme de sinais vitais', 'sortOrder' => 20, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'radar', 'section' => 'alarms', 'key' => 'presence_event', 'label' => 'Entradas e saídas', 'sortOrder' => 30, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ];
    }
}
