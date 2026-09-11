<?php

namespace Hub\Command\Configuration\Definition;

/**
 * O que se configura numa pulseira Veepoo.
 *
 * **Só entra aqui o que muda o que o aparelho mede ou como calcula.** A pulseira deixa
 * configurar bastante mais -- alarmes, lembretes para beber água, o ecrã que acende ao
 * levantar o pulso, o sistema de unidades --, e nada disso altera uma leitura: é
 * comportamento de relógio de pulso, não de sensor. O hub não o expõe, porque uma
 * configuração que não muda telemetria é ruído numa API de integração de saúde.
 *
 * Pelo mesmo critério ficam de fora os campos que o comando `0xB8` aceita mas que este
 * hardware não tem -- deteção de queda, ECG sempre ligado, controlo de música: ao ler as
 * definições o aparelho responde `noThisFeature` a cada um.
 *
 * O `command` é o nome da operação na ponte e não uma trama: quem escreve na pulseira é o
 * gateway que tem a sessão BLE, e o hub não monta tramas Veepoo. A confirmação vem de o
 * gateway reler o aparelho, e não do eco do comando.
 */
final class VeepooConfigurationDefinitions
{
    /**
     * Chave genérica do hub => [rótulo, secção, o que faz ao aparelho].
     *
     * A terceira coluna é o que o operador precisa de saber para decidir, e que o nome
     * sozinho não diz: que a tendência de tensão mede de dez em dez minutos, ou que a
     * temperatura contínua é da pele e não do corpo. A ordem é a que faz sentido ler.
     */
    private const SWITCHES = [
        'heart_rate_continuous' => ['Frequência cardíaca contínua', 'health', 'Mede ao longo do dia, um valor por minuto.'],
        'blood_pressure_trend' => ['Tendência da pressão arterial', 'health', 'Estima a tensão de dez em dez minutos.'],
        'temperature_continuous' => ['Temperatura contínua', 'health', 'Amostra a temperatura da pele. A do corpo só existe a pedido.'],
        'hrv_continuous' => ['VFC contínua', 'health', 'Variabilidade cardíaca ao longo do dia.'],
        'blood_sugar_continuous' => ['Glicemia contínua', 'health', 'Estimativa ótica, sem picada. Não é clinicamente validada.'],
        'blood_lipids_continuous' => ['Composição sanguínea contínua', 'health', 'Estima lípidos e ácido úrico. Não é clinicamente validada.'],
        'stress_continuous' => ['Stress contínuo', 'health', 'Índice calculado a partir da variabilidade cardíaca.'],
        'sleep_monitoring' => ['Monitorização do sono', 'health', 'Grava as fases de sono enquanto a pulseira estiver ao pulso.'],
        // Não é medição contínua de oxigénio: essa a pulseira faz sempre e vem nos blocos.
        // Este é o despertar por hipoxia, e por isso vive entre os alarmes.
        'blood_oxygen_alert' => ['Alerta de oxigénio no sangue', 'alarms', 'Acorda quem a usa se a saturação descer demasiado durante o sono.'],
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $order = 10;
        $configs = [];
        foreach (self::SWITCHES as $key => [$label, $section, $help]) {
            $configs[] = ConfigurationDefinition::make(
                $key,
                'config:' . $key,
                $label,
                'toggle',
                ['enabled'],
                ['monitoring'],
                $section,
                $order,
                help: $help,
            );
            $order += 10;
        }

        // A janela é o que faz a diferença entre a série de dia inteiro trazer apneia e
        // hipóxia ou vir vazia: sem ela a monitorização fica ligada a não medir nada.
        $configs[] = ConfigurationDefinition::make(
            'blood_oxygen_window',
            'config:blood_oxygen_window',
            'Oxigénio de dia inteiro',
            'windowToggle',
            ['enabled', 'range'],
            ['monitoring'],
            'health',
            $order,
            help: 'Mede a saturação dentro da janela indicada. Daqui saem a apneia, a hipóxia e a carga cardíaca.',
        );
        $order += 10;

        // Os limiares são avaliados pelo aparelho sobre a medição dele, e não pela aplicação.
        $configs[] = ConfigurationDefinition::make(
            'heart_rate_alert',
            'config:heart_rate_alert',
            'Alerta de frequência cardíaca',
            'heartRateThresholds',
            ['enabled', 'maxBpm', 'minBpm'],
            ['monitoring'],
            'alarms',
            $order,
            help: 'Avisa quem a usa quando a frequência cardíaca sai destes limites.',
        );
        $order += 10;

        // Regula a potência do LED do sensor ótico. Tudo o que a pulseira mede por luz --
        // frequência cardíaca, oxigénio, VFC, tensão, stress -- sai deste sinal.
        $configs[] = ConfigurationDefinition::make(
            'skin_tone',
            'config:skin_tone',
            'Tom de pele',
            'number',
            ['level'],
            ['monitoring'],
            'health',
            $order,
            options: ['min' => 1, 'max' => 6, 'label' => 'Nível'],
            help: 'Calibra o sensor ótico, de 1 (mais claro) a 6 (mais escuro).',
        );
        $order += 10;

        // Não é identificação de quem usa a pulseira: é o corpo com que ela calcula. Sem
        // isto as calorias e a composição corporal saem calibradas para um valor de fábrica.
        $configs[] = ConfigurationDefinition::make(
            'personal_info',
            'config:personal_info',
            'Dados para cálculo',
            'personalInfo',
            ['heightCm', 'weightKg', 'age', 'sex', 'stepGoal', 'sleepGoalMinutes'],
            ['monitoring'],
            'health',
            $order,
            help: 'Altura, peso, idade e sexo entram no cálculo das calorias e da composição corporal.',
        );
        $order += 10;

        // Não é uma definição: a pulseira vibra no instante em que recebe a ordem e não
        // guarda estado nenhum. Por isso é transiente -- pede-se por `/requests` e a `PATCH`
        // recusa-a, que é como o hub separa o que se configura do que se manda fazer.
        $configs[] = ConfigurationDefinition::make(
            'find_device',
            'config:find_device',
            'Encontrar dispositivo',
            'toggle',
            ['enabled'],
            ['find_device'],
            'settings_system',
            $order,
            transient: true,
            help: 'Faz a pulseira vibrar. Pára sozinha ao fim de cerca de um minuto.',
            actions: ['on' => 'Fazer vibrar', 'off' => 'Parar'],
        );

        return $configs;
    }
}
