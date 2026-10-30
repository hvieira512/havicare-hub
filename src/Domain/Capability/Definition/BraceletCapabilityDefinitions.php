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
     * A Veepoo é a que fala: tem sessão GATT e publica tudo o que mede. As W6/W6B só anunciam,
     * e o que anunciam está no `publishedBy`.
     */
    protected static function defaultPublishedBy(): array
    {
        return ['veepoo-ble'];
    }

    protected static function publishedBy(): array
    {
        return [
            'battery' => ['veepoo-ble', 'moko-w6b', 'moko-w6'],
            // Vêm do anúncio BLE e do avistamento por um gateway, que é o que as W6/W6B dão.
            'motion' => ['moko-w6b', 'moko-w6'],
            'proximity' => ['moko-w6b', 'moko-w6'],
            'proximity_alarm' => ['moko-w6b', 'moko-w6'],
            'help_call' => ['moko-w6b', 'moko-w6'],
            // A MF91 não exporta onda nenhuma: o que a app do fabricante chama `ppgs` são as
            // cinco frequências de pulso do bloco, que já saem como `heart_rate`.
            'ppg' => [],
            // Uma ação, mas a resposta da pulseira sai na telemetria.
            'find_device' => ['veepoo-ble'],
        ];
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                // Só as pulseiras com sessão GATT respondem, e só entra o que, testado no aparelho, devolve um
                // valor ou uma razão: um botão que nunca responde é pior do que nenhum.
                'measurementOnRequest' => [
                    'battery',
                    'heart_rate',
                    'blood_pressure',
                    'blood_oxygen',
                    'blood_sugar',
                    'ecg',
                    'temperature',
                    'sleep',
                    // O acumulado do dia, como o `steps` dos relógios: um contador desde a meia-noite.
                    'activity',
                    'stress',
                    'body_composition',
                ],
                // O avistamento é o que sustenta os alarmes de proximidade. O `motion` não
                // entra: vem no anúncio BLE e é o acelerómetro da própria pulseira.
                'sighting' => [
                    'proximity',
                ],
                'measurement' => [
                    'motion',
                    'hrv',
                    'rr_interval',
                    'breath_rate',
                    'ppg',
                    // As pontuações que o firmware dá à noite: são um juízo e não a medição, e ficam fora do
                    // `sleep`, que é o contrato dos relógios.
                    'sleep_quality',
                    // Os passos de cada bloco de cinco minutos, distintos da `activity`, que é o acumulado do dia.
                    'steps',
                    // Derivados que a pulseira calcula sozinha e entrega nos blocos diários:
                    // não há comando que os mande medir, saem do que já foi medido.
                    'met',
                    'blood_lipids',
                    'uric_acid',
                    'sleep_apnea',
                    'cardiac_load',
                    // A pulseira diz em cada bloco se estava ao pulso, para os zeros da mesinha não passarem por
                    // quem está sentado.
                    'wear_state',
                    'firmware_version',
                ],
            ],
            'health' => [
                // Interruptores de medição autónoma, com as chaves dos relógios porque é a mesma capacidade.
                // Só entra o que muda o que o aparelho mede ou como calcula.
                'setting' => [
                    'heart_rate_continuous',
                    'blood_pressure_trend',
                    'temperature_continuous',
                    'hrv_continuous',
                    'blood_sugar_continuous',
                    'blood_lipids_continuous',
                    'stress_continuous',
                    'sleep_monitoring',
                    'blood_oxygen_window',
                    // Não são preferências, entram nas contas: o tom de pele regula a potência do LED ótico, e o
                    // corpo serve às calorias e à composição.
                    'skin_tone',
                    'personal_info',
                ],
            ],
            'alarms' => [
                // Os limiares são avaliados pelo aparelho sobre a medição dele.
                'setting' => [
                    // O despertar por hipoxia, e não a medição contínua de oxigénio, que vem nos blocos; nos
                    // relógios é o mesmo `blood_oxygen_alert`.
                    'blood_oxygen_alert',
                    'heart_rate_alert',
                ],
                'event' => [
                    'help_call',
                    'low_battery',
                    // Decidido pelo hub, com o alarme de proximidade do gateway que a ouve.
                    'proximity_alarm',
                ],
            ],
            'settings_system' => [
                // Faz a pulseira vibrar até alguém a encontrar. É acção e não configuração
                // porque não há estado a guardar -- pede-se, e pede-se outra vez para parar.
                'action' => [
                    'find_device',
                ],
            ],
        ];
    }
}
