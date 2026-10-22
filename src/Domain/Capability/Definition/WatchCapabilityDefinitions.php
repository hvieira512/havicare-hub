<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

final class WatchCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'watch';
    }

    /**
     * O que cada relógio publica além do que os três publicam todos -- bateria, atividade,
     * localização e as quatro medições de rotina.
     */
    protected static function publishedBy(): array
    {
        return [
            // Que rádio serve a ligação sai na resposta ao `TS`, que é só da 4P Touch.
            'connectivity' => ['four-p-touch'],
            'blood_sugar' => ['wonlex-json', 'vivistar-iw'],
            // O que só a Wonlex mede: as outras duas não trazem forma de ondas nem sono.
            'breath_rate' => ['wonlex-json'],
            'sleep' => ['wonlex-json'],
            'ecg' => ['wonlex-json'],
            'hrv' => ['wonlex-json'],
            'ppg' => ['wonlex-json'],
            'rr_interval' => ['wonlex-json'],
            // Nenhum as anuncia: chegam só como resposta a um pedido, pelo catálogo de
            // comandos.
            'firmware_version' => [],
            'device_status' => [],
            // O que o aparelho dispara: `AP10` na Vivistar e os `AL*` na 4P Touch.
            'help_call' => ['vivistar-iw', 'four-p-touch'],
            'fall' => ['vivistar-iw', 'four-p-touch'],
            'low_battery' => ['vivistar-iw', 'four-p-touch', 'wonlex-json'],
            'device_removed' => ['vivistar-iw', 'four-p-touch'],
            'zone_entry' => ['four-p-touch'],
            'zone_exit' => ['four-p-touch'],
            // O bit 22 do `AL` da 4P Touch.
            'heart_rate_abnormal' => ['four-p-touch'],
            'device_state' => ['wonlex-json'],
        ];
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                'measurement' => [
                    'battery',
                    // Que rádio serve a ligação, e quais estão acesos. Chega na resposta ao
                    // `TS`; é a mesma capacidade que os gateways e o dispensador publicam.
                    'connectivity',
                    'activity',
                    'blood_sugar',
                    'sleep',
                ],
                'measurementOnRequest' => [
                    'heart_rate',
                    'blood_pressure',
                    'blood_oxygen',
                    'temperature',
                    'breath_rate',
                    'location',
                    'ecg',
                    'hrv',
                    'ppg',
                    'rr_interval',
                    'firmware_version',
                    // O `TS` dos 4P Touch. Não traz valor próprio: o que ele responde enche
                    // as leituras que já existem, como o `0x07` do dispensador.
                    'device_status',
                ],
            ],
            'health' => [
                'setting' => [
                    'auto_vitals_interval',
                    'heart_rate_measurement_interval',
                    'blood_pressure_measurement_interval',
                    'blood_oxygen_measurement_interval',
                    'temperature_measurement_interval',
                    'breath_rate_measurement_interval',
                    'ecg_measurement_interval',
                    'hrv_measurement_interval',
                    'ppg_measurement_interval',
                    'rr_interval_measurement_interval',
                    'heart_rate_continuous',
                    'blood_oxygen_continuous',
                    'blood_pressure_trend',
                    'temperature_continuous',
                    'step_goal',
                    'sleep_monitoring',
                    // A pergunta é de quanto em quanto tempo o relógio envia, como no dos
                    // passos, e não uma definição do aparelho.
                    'location_reporting_interval',
                    'step_reporting_interval',
                    'pedometer_schedule',
                ],
            ],
            'contacts' => [
                'setting' => [
                    'phonebook',
                    'call_whitelist',
                    'whitelist_enabled',
                    'sos_contacts',
                    'center_number',
                ],
            ],
            'reminders' => [
                'setting' => [
                    'medication_reminders',
                ],
            ],
            'alarms' => [
                'setting' => [
                    // hitcare: o hitCare procura o alarm_clock só em capabilities.alarms; passa para os
                    // reminders quando o ajax/custom.php o procurar lá.
                    'alarm_clock',
                    'low_battery_alert',
                    'fall_detection',
                    'fall_sensitivity',
                    'sos_sms_alert',
                    'blood_oxygen_alert',
                    'temperature_high_alert',
                    'temperature_low_alert',
                    'blood_pressure_alert',
                    'heart_rate_high_alert',
                    'heart_rate_low_alert',
                    'remove_watch_alarm',
                    'remove_watch_sms_alert',
                ],
                'event' => [
                    'help_call',
                    'fall',
                    'low_battery',
                    'device_removed',
                    'zone_entry',
                    'zone_exit',
                    'heart_rate_abnormal',
                ],
            ],
            'settings_system' => [
                'setting' => [
                    'working_mode',
                    'language_timezone',
                    'do_not_disturb',
                    'sound_profile',
                ],
                'action' => [
                    'push_message',
                    'make_call',
                    // Acção e não contacto: o relógio liga para o número mal recebe o comando, em escuta silenciosa.
                    'monitor_number',
                    'reset_device',
                    'restart_device',
                    'power_off',
                    'find_device',
                ],
                'event' => [
                    'device_state',
                ],
            ],
        ];
    }
}
