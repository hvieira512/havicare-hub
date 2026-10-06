<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

/**
 * O que se configura numa pulseira Veepoo: só o que muda o que ela mede ou calcula. O
 * `command` é o nome da operação na ponte, porque quem escreve é o gateway com a sessão BLE.
 */
final class VeepooConfigurationDefinitions
{
    /**
     * Chave genérica do hub => [rótulo, secção, o que faz ao aparelho].
     *
     * A terceira coluna é o que o operador precisa de saber e o nome sozinho não diz; vazia
     * quando o rótulo basta. A ordem é a que faz sentido ler.
     */
    private const SWITCHES = [
        'heart_rate_continuous' => ['Frequência cardíaca contínua', 'health', 'Um valor por minuto.'],
        'blood_pressure_trend' => ['Tendência da pressão arterial', 'health', 'Um par sistólica/diastólica a cada cinco minutos.'],
        'temperature_continuous' => ['Temperatura contínua', 'health', 'Corporal e de superfície, a cada cinco minutos.'],
        'hrv_continuous' => ['Variabilidade cardíaca contínua', 'health', ''],
        'blood_sugar_continuous' => ['Glicemia contínua', 'health', 'Estimativa ótica, sem picada.'],
        'blood_lipids_continuous' => ['Lípidos e ácido úrico contínuos', 'health', 'Estima colesterol, triglicéridos e ácido úrico.'],
        'stress_continuous' => ['Stress contínuo', 'health', 'Índice de 0 a 100.'],
        'sleep_monitoring' => ['Monitorização do sono', 'health', ''],
        // Não é medição contínua de oxigénio: essa a pulseira faz sempre e vem nos blocos.
        // Este é o despertar por hipoxia, e por isso vive entre os alarmes.
        'blood_oxygen_alert' => ['Alerta de oxigénio no sangue', 'alarms', 'Acorda quem a usa com a saturação baixa; o hub não recebe o alerta.'],
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

        $configs[] = ConfigurationDefinition::make(
            'blood_oxygen_window',
            'config:blood_oxygen_window',
            'Oxigénio de dia inteiro',
            'windowToggle',
            ['enabled', 'range'],
            ['monitoring'],
            'health',
            $order,
            help: 'Daqui saem a apneia, a hipóxia e a carga cardíaca.',
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
            help: 'O aviso fica na pulseira; o hub não é avisado.',
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
            help: 'Entram no cálculo das calorias e da composição corporal.',
        );
        $order += 10;

        // Transiente: a pulseira vibra quando recebe a ordem e não guarda estado, por isso pede-se
        // por `/requests` e a `PATCH` recusa-a.
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
            help: 'Pára sozinha ao fim de cerca de um minuto.',
            actions: ['on' => 'Fazer vibrar', 'off' => 'Parar'],
        );

        return $configs;
    }
}
