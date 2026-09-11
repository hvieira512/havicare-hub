<?php

namespace Hub\Domain\Capability\Definition;

final class BraceletCapabilityDefinitions
{
    /**
     * O botão é uma capacidade só e não uma por modo de toque: os modos configuram-se no
     * aparelho, e o tipo de toque viaja no payload do evento. Separá-los aqui punha três
     * interruptores na matriz de capacidades para o que é uma funcionalidade física.
     *
     * Nem todas as pulseiras se limitam a anunciar. As W6 e W6B emitem para o ar e nada mais
     * lhes pode ser pedido, mas as que falam por GATT autenticado -- a Veepoo MF91 é a
     * primeira -- respondem a comandos, e seis das suas grandezas podem ser medidas a pedido.
     * O que decide não é o tipo de aparelho mas o modelo, e é por isso que `is_requestable`
     * também existe em `model_capabilities`: aqui declara-se o que é possível, lá o que cada
     * modelo faz.
     *
     * @return list<array{deviceType: string, section: string, key: string, label: string, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            // Pedível nas pulseiras com sessão GATT, que respondem à pergunta. As que só
            // anunciam continuam sem card, porque o catálogo de comandos do protocolo delas
            // está vazio -- é lá que a diferença se faz, não aqui.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'motion', 'label' => 'Movimento', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // Sai por avistamento, e não do aparelho: é a força com que cada gateway o ouve,
            // que é o que sustenta os alarmes de proximidade. Nunca é pedível, porque não é
            // o aparelho que a produz.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'proximity', 'label' => 'Proximidade', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'alarms', 'key' => 'help_call', 'label' => 'Chamada de ajuda', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],

            // Pulseiras com sessão GATT. Só é pedível o que, testado no aparelho, devolve uma
            // resposta conclusiva -- um valor ou uma razão. Prometer um botão que nunca
            // responde é pior do que não o ter: o cuidador carrega, não acontece nada, e
            // deixa de confiar nos que funcionam.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'heart_rate', 'label' => 'Frequência cardíaca', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'blood_pressure', 'label' => 'Pressão arterial', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'blood_oxygen', 'label' => 'Oxigénio no sangue', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'blood_sugar', 'label' => 'Glicemia', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'ecg', 'label' => 'ECG', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],

            // A temperatura mede-se sozinha e chega nos blocos diários com valores reais, mas
            // o comando de medição a pedido responde sempre com carga vazia -- dez respostas,
            // zero conteúdo, nem sequer um estado de erro. Fica como telemetria e não como
            // pedido: o dado existe, o botão é que não teria o que devolver.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'temperature', 'label' => 'Temperatura', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],

            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'hrv', 'label' => 'HRV', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'rr_interval', 'label' => 'Intervalo R-R', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'breath_rate', 'label' => 'Frequência respiratória', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'ppg', 'label' => 'PPG', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'sleep', 'label' => 'Sono', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // O rótulo diz a janela: ao lado do total do dia, «Atividade: 0 passos» e «Total
            // do dia: 216 passos» liam-se como uma contradição em vez de duas escalas.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'activity', 'label' => 'Atividade (5 min)', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],

            // Derivados que a pulseira calcula sozinha e entrega nos blocos diários. Nenhum é
            // pedível: não há comando que os mande medir, saem do que já foi medido.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'stress', 'label' => 'Stress', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'met', 'label' => 'MET', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'blood_lipids', 'label' => 'Lípidos no sangue', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'uric_acid', 'label' => 'Ácido úrico', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'sleep_apnea', 'label' => 'Apneia do sono', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'cardiac_load', 'label' => 'Carga cardíaca', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // A pulseira diz em cada bloco se estava ao pulso. Sem isto, um bloco de zeros
            // por estar na mesinha é igual a um bloco de zeros de quem está sentado.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'wear_state', 'label' => 'Estado de uso', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // O acumulado do dia, contado pela pulseira. Distinto do `activity`, que é o que
            // se andou em cinco minutos: somar os dois contava tudo duas vezes.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'activity_daily', 'label' => 'Total do dia', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            // A pulseira diz a versão em cada sessão; sem isto não havia onde a guardar.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'firmware_version', 'label' => 'Versão de firmware', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'body_composition', 'label' => 'Composição corporal', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],

            // Interruptores de medição autónoma. A pulseira mede sozinha ao longo do dia e
            // guarda; estes dizem-lhe o que medir. São as mesmas chaves dos relógios, porque
            // é a mesma capacidade -- inventar `bracelet_heart_rate_continuous` obrigaria
            // quem integra a tratar por dois nomes o que é uma coisa só.
            ['deviceType' => 'bracelet', 'section' => 'health', 'key' => 'heart_rate_continuous', 'label' => 'Frequência cardíaca contínua', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'health', 'key' => 'blood_pressure_trend', 'label' => 'Tendência da pressão arterial', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'health', 'key' => 'temperature_continuous', 'label' => 'Temperatura contínua', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'health', 'key' => 'hrv_continuous', 'label' => 'VFC contínua', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'health', 'key' => 'blood_sugar_continuous', 'label' => 'Glicemia contínua', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'health', 'key' => 'blood_lipids_continuous', 'label' => 'Composição sanguínea contínua', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'health', 'key' => 'stress_continuous', 'label' => 'Stress contínuo', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'health', 'key' => 'sleep_monitoring', 'label' => 'Monitorização do sono', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            // Não é medição contínua de oxigénio -- essa a pulseira faz sempre, e vem nos
            // blocos. Este interruptor é o despertar por hipoxia, e o hub já chama
            // `blood_oxygen_alert` à mesma coisa nos relógios.
            ['deviceType' => 'bracelet', 'section' => 'alarms', 'key' => 'blood_oxygen_alert', 'label' => 'Alerta de oxigénio no sangue', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            // Faz a pulseira vibrar até alguém a encontrar. Não é configurável porque não há
            // estado a guardar -- pede-se, e pede-se outra vez para parar.
            ['deviceType' => 'bracelet', 'section' => 'settings_system', 'key' => 'find_device', 'label' => 'Encontrar dispositivo', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
        ];
    }
}
