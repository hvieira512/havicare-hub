<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

/**
 * O botão é uma capacidade só, e o tipo de toque viaja no payload do evento. Aqui declara-se o
 * que é pedível; o `is_requestable` do `model_capabilities` diz o que cada modelo faz.
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
                // Só as pulseiras com sessão GATT respondem, e só entra o que, testado no aparelho, devolve um
                // valor ou uma razão: um botão que nunca responde é pior do que nenhum.
                'measurementOnRequest' => [
                    'battery' => 'Bateria',
                    'heart_rate' => 'Frequência cardíaca',
                    'blood_pressure' => 'Pressão arterial',
                    'blood_oxygen' => 'Oxigénio no sangue',
                    'blood_sugar' => 'Glicemia',
                    'ecg' => 'ECG',
                    'temperature' => 'Temperatura corporal',
                    'sleep' => 'Sono',
                    // O acumulado do dia, como o `steps` dos relógios: um contador desde a meia-noite.
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
                    // As pontuações que o firmware dá à noite: são um juízo e não a medição, e ficam fora do
                    // `sleep`, que é o contrato dos relógios.
                    'sleep_quality' => 'Qualidade do sono',
                    // Os passos de cada bloco de cinco minutos, distintos da `activity`, que é o acumulado do dia.
                    'steps' => 'Passos por período',
                    // Derivados que a pulseira calcula sozinha e entrega nos blocos diários:
                    // não há comando que os mande medir, saem do que já foi medido.
                    'met' => 'MET',
                    'blood_lipids' => 'Lípidos no sangue',
                    'uric_acid' => 'Ácido úrico',
                    'sleep_apnea' => 'Apneia do sono',
                    'cardiac_load' => 'Carga cardíaca',
                    // A pulseira diz em cada bloco se estava ao pulso, para os zeros da mesinha não passarem por
                    // quem está sentado.
                    'wear_state' => 'Estado de uso',
                    'firmware_version' => 'Versão do firmware',
                ],
            ],
            'health' => [
                // Interruptores de medição autónoma, com as chaves dos relógios porque é a mesma capacidade.
                // Só entra o que muda o que o aparelho mede ou como calcula.
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
                    // Não são preferências, entram nas contas: o tom de pele regula a potência do LED ótico, e o
                    // corpo serve às calorias e à composição.
                    'skin_tone' => 'Tom de pele',
                    'personal_info' => 'Dados para cálculo',
                ],
            ],
            'alarms' => [
                // Os limiares são avaliados pelo aparelho sobre a medição dele.
                'setting' => [
                    // O despertar por hipoxia, e não a medição contínua de oxigénio, que vem nos blocos; nos
                    // relógios é o mesmo `blood_oxygen_alert`.
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
