<?php

namespace Hub\Command\Configuration\Definition;

/**
 * O que se configura numa pulseira Veepoo.
 *
 * Interruptores do mesmo comando `0xB8`. Dizem à pulseira o que medir sozinha ao longo do
 * dia -- é o que faz a diferença entre um aparelho que acumula sinais vitais e um que só
 * conta passos, porque nada disto é medido a menos que esteja ligado.
 *
 * Só aqui estão os que a MF91 tem mesmo. O comando aceita mais campos -- deteção de queda,
 * ECG sempre ligado, controlo de música --, mas ao ler as definições o aparelho responde
 * `noThisFeature` a cada um deles: o hardware não os tem, e oferecê-los na dashboard era um
 * interruptor que muda um estado guardado e mais nada.
 *
 * O `command` é o nome da operação na ponte e não uma trama: quem escreve na pulseira é o
 * gateway que tem a sessão BLE, e o hub não monta tramas Veepoo. A escrita é
 * read-modify-write no firmware -- o gateway lê o estado antes de alterar um bit --, e por
 * isso confirma-se relendo o aparelho e não pelo eco do comando.
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
