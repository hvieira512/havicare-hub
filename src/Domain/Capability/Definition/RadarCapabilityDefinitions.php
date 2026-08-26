<?php

namespace Hub\Domain\Capability\Definition;

/**
 * As capacidades do radar nomeiam o que se mede, e não as mensagens do fabricante.
 *
 * Eram quatro -- `positions`, `vitals`, `position_minute_stats`, `vitals_minute_stats` --
 * que são os quatro tipos de mensagem do protocolo Qinglanst com outro nome. Era o único
 * tipo de dispositivo assim: o relógio, a pulseira, a fralda e o gateway nomeiam grandezas.
 *
 * A frequência cardíaca e a respiratória são as mesmas do relógio, com as mesmas chaves e
 * as mesmas formas (`FeatureNormalizer::heartRate` dá `{bpm}`), por isso reaproveitam os
 * cartões que já existem em vez de terem os seus.
 *
 * O `sleep_state` não é o `sleep` do relógio: aquele é um relatório com segmentos e
 * duração, este é o estado no instante em que se mede. O `presence` também não é o
 * `location`, que é geográfico -- o radar dá x/y/z em decímetros relativos a si próprio.
 *
 * A postura não é capacidade nenhuma: é de cada pessoa, tal como a posição, e vive dentro
 * do `presence` ao lado dela. Uma divisão com duas pessoas tem duas posturas, e não há
 * leitura do aparelho que as represente sem escolher uma e deitar a outra fora.
 *
 * Os dois envelopes por minuto ficam como estavam: são agregados, não leituras, e misturar
 * uma média de sessenta segundos com uma leitura de um na mesma série não ajudava ninguém.
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
