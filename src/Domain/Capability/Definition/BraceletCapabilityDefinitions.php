<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

/**
 * O botão é uma capacidade só e não uma por modo de toque: os modos configuram-se no
 * aparelho, e o tipo de toque viaja no payload do evento.
 *
 * O que decide se uma grandeza se pede não é o tipo de aparelho mas o modelo, e é por
 * isso que `is_requestable` também existe em `model_capabilities`: aqui declara-se o que
 * é possível, lá o que cada modelo faz.
 */
final class BraceletCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'bracelet';
    }

    /**
     * A Veepoo é a que fala: tem sessão GATT e publica tudo o que mede e tudo o que tem
     * configurado. As W6/W6B só anunciam, e o que anunciam está no `publishedBy`.
     */
    protected static function defaultPublishedBy(): array
    {
        return ['veepoo-ble'];
    }

    protected static function publishesOwnConfiguration(): bool
    {
        return true;
    }

    protected static function publishedBy(): array
    {
        return [
            'battery' => ['veepoo-ble', 'moko-w6b', 'moko-w6'],
            // Vêm do anúncio BLE e do avistamento por um gateway, que é o que as W6/W6B dão.
            'motion' => ['moko-w6b', 'moko-w6'],
            'proximity' => ['moko-w6b', 'moko-w6'],
            'help_call' => ['moko-w6b', 'moko-w6'],
            // A MF91 não exporta onda nenhuma: o que a app do fabricante chama `ppgs` são as
            // cinco frequências de pulso do bloco, que já saem como `heart_rate`.
            'ppg' => [],
        ];
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                // As pulseiras com sessão GATT respondem à pergunta; as que só anunciam
                // continuam sem card, porque o catálogo de comandos do protocolo delas está
                // vazio -- é lá que a diferença se faz, não aqui.
                //
                // Só entra aqui o que, testado no aparelho, devolve uma resposta conclusiva:
                // um valor ou uma razão. Prometer um botão que nunca responde é pior do que
                // não o ter -- o cuidador carrega, não acontece nada, e deixa de confiar nos
                // que funcionam.
                'measurementOnRequest' => [
                    'battery' => 'Bateria',
                    'heart_rate' => 'Frequência cardíaca',
                    'blood_pressure' => 'Pressão arterial',
                    'blood_oxygen' => 'Oxigénio no sangue',
                    'blood_sugar' => 'Glicemia',
                    'ecg' => 'ECG',
                    'temperature' => 'Temperatura corporal',
                    'sleep' => 'Sono',
                    // O acumulado do dia, como nos relógios: lá o `steps` do aparelho é um
                    // contador desde a meia-noite, e é o mesmo que a pulseira dá quando lhe
                    // perguntam.
                    'activity' => 'Atividade',
                    'stress' => 'Stress',
                    'body_composition' => 'Composição corporal',
                ],
                // O avistamento é o que sustenta os alarmes de proximidade. O `motion` não
                // entra: vem no anúncio BLE e é o acelerómetro da própria pulseira.
                'sighting' => [
                    'proximity' => 'Proximidade',
                ],
                'measurement' => [
                    'motion' => 'Movimento',
                    'hrv' => 'VFC',
                    'rr_interval' => 'Intervalo R-R',
                    'breath_rate' => 'Frequência respiratória',
                    'ppg' => 'PPG',
                    // As pontuações que o firmware atribui à noite. São um juízo sobre a
                    // medição e não a medição, e por isso não cabem no `sleep`, que é o
                    // mesmo contrato dos relógios -- nenhum deles pontua o sono.
                    'sleep_quality' => 'Qualidade do sono',
                    // Os passos de cada bloco de cinco minutos, que é quando eles foram
                    // dados. A `activity` é o acumulado do dia, e a etiqueta tem de as
                    // distinguir: lado a lado no mesmo ecrã, «Passos» sozinho não dizia qual.
                    'steps' => 'Passos por período',
                    // Derivados que a pulseira calcula sozinha e entrega nos blocos diários:
                    // não há comando que os mande medir, saem do que já foi medido.
                    'met' => 'MET',
                    'blood_lipids' => 'Lípidos no sangue',
                    'uric_acid' => 'Ácido úrico',
                    'sleep_apnea' => 'Apneia do sono',
                    'cardiac_load' => 'Carga cardíaca',
                    // A pulseira diz em cada bloco se estava ao pulso. Sem isto, um bloco de
                    // zeros por estar na mesinha é igual a um bloco de zeros de quem está
                    // sentado.
                    'wear_state' => 'Estado de uso',
                    // A pulseira diz a versão em cada sessão; sem isto não havia onde a
                    // guardar.
                    'firmware_version' => 'Versão do firmware',
                ],
            ],
            'health' => [
                // Interruptores de medição autónoma: a pulseira mede sozinha ao longo do dia
                // e guarda, e estes dizem-lhe o que medir. São as mesmas chaves dos relógios,
                // porque é a mesma capacidade -- inventar `bracelet_heart_rate_continuous`
                // obrigaria quem integra a tratar por dois nomes o que é uma coisa só.
                //
                // Só entra o que muda o que o aparelho mede ou como calcula: alarmes,
                // lembretes, brilho do ecrã e unidades são comportamento de relógio de pulso
                // e não alteram uma leitura.
                'setting' => [
                    'heart_rate_continuous' => 'Frequência cardíaca contínua',
                    'blood_pressure_trend' => 'Tendência da pressão arterial',
                    'temperature_continuous' => 'Temperatura contínua',
                    'hrv_continuous' => 'VFC contínua',
                    'blood_sugar_continuous' => 'Glicemia contínua',
                    'blood_lipids_continuous' => 'Composição sanguínea contínua',
                    'stress_continuous' => 'Stress contínuo',
                    'sleep_monitoring' => 'Monitorização do sono',
                    'blood_oxygen_window' => 'Oxigénio de dia inteiro',
                    // Estas duas não são preferências de quem usa a pulseira: entram nas
                    // contas dela. O tom de pele regula a potência do LED de que sai todo o
                    // sinal ótico, e o corpo é o que ela usa para calorias e composição.
                    'skin_tone' => 'Tom de pele',
                    'personal_info' => 'Dados para cálculo',
                ],
            ],
            'alarms' => [
                // Os limiares são avaliados pelo aparelho sobre a medição dele.
                'setting' => [
                    // Não é medição contínua de oxigénio -- essa a pulseira faz sempre, e vem
                    // nos blocos. Este interruptor é o despertar por hipoxia, e o hub já
                    // chama `blood_oxygen_alert` à mesma coisa nos relógios.
                    'blood_oxygen_alert' => 'Alerta de oxigénio no sangue',
                    'heart_rate_alert' => 'Alerta de frequência cardíaca',
                ],
                'event' => [
                    'help_call' => 'Chamada de ajuda',
                ],
            ],
            'settings_system' => [
                // Faz a pulseira vibrar até alguém a encontrar. É acção e não configuração
                // porque não há estado a guardar -- pede-se, e pede-se outra vez para parar.
                'action' => [
                    'find_device' => 'Encontrar dispositivo',
                ],
            ],
        ];
    }
}
