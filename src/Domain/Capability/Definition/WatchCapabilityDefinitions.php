<?php

namespace Hub\Domain\Capability\Definition;

final class WatchCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'watch';
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                'measurement' => [
                    'battery' => 'Bateria',
                    'activity' => 'Atividade (passos)',
                    'blood_sugar' => 'Glicemia',
                    'sleep' => 'Sono',
                ],
                'measurementOnRequest' => [
                    'heart_rate' => 'Frequência cardíaca',
                    'blood_pressure' => 'Pressão arterial',
                    'blood_oxygen' => 'Oxigénio no sangue',
                    'temperature' => 'Temperatura corporal',
                    'breath_rate' => 'Frequência respiratória',
                    'location' => 'Localização',
                    'ecg' => 'ECG',
                    'hrv' => 'VFC',
                    'ppg' => 'PPG',
                    'rr_interval' => 'Intervalo R-R',
                    'firmware_version' => 'Versão do firmware',
                ],
            ],
            'health' => [
                'setting' => [
                    'auto_vitals_interval' => 'Intervalo de sinais vitais automáticos',
                    'heart_rate_measurement_interval' => 'Intervalo de medição da frequência cardíaca',
                    'blood_pressure_measurement_interval' => 'Intervalo de medição da pressão arterial',
                    'blood_oxygen_measurement_interval' => 'Intervalo de medição do oxigénio no sangue',
                    'temperature_measurement_interval' => 'Intervalo de medição da temperatura',
                    'breath_rate_measurement_interval' => 'Intervalo de medição da frequência respiratória',
                    'ecg_measurement_interval' => 'Intervalo de medição do ECG',
                    'hrv_measurement_interval' => 'Intervalo de medição da VFC',
                    'ppg_measurement_interval' => 'Intervalo de medição da PPG',
                    'rr_interval_measurement_interval' => 'Intervalo de medição do RR',
                    'heart_rate_continuous' => 'Frequência cardíaca contínua',
                    'blood_oxygen_continuous' => 'Oxigénio no sangue contínuo',
                    'blood_pressure_trend' => 'Tendência da pressão arterial',
                    'temperature_continuous' => 'Temperatura contínua',
                    'step_goal' => 'Meta de passos',
                    'sleep_monitoring' => 'Monitorização do sono',
                    // A pergunta é de quanto em quanto tempo o relógio envia, como no dos
                    // passos, e não uma definição do aparelho.
                    'location_reporting_interval' => 'Intervalo de envio da localização',
                    'step_reporting_interval' => 'Intervalo de envio dos passos',
                    'pedometer_schedule' => 'Horário do pedómetro',
                ],
            ],
            'contacts' => [
                'setting' => [
                    'phonebook' => 'Lista telefónica',
                    'call_whitelist' => 'Lista de chamadas autorizadas',
                    'whitelist_enabled' => 'Restringir chamadas recebidas',
                    'sos_contacts' => 'Contactos SOS',
                    'center_number' => 'Número da central',
                ],
            ],
            'alarms' => [
                'setting' => [
                    'alarm_clock' => 'Alarmes',
                    'medication_reminders' => 'Plano de medicação',
                    'low_battery_alert' => 'Alerta de bateria fraca',
                    'fall_detection' => 'Deteção de queda',
                    'fall_sensitivity' => 'Sensibilidade de queda',
                    'sos_sms_alert' => 'Alerta SOS por SMS',
                    'blood_oxygen_alert' => 'Alerta de oxigénio no sangue',
                    'temperature_high_alert' => 'Alerta de temperatura alta',
                    'temperature_low_alert' => 'Alerta de temperatura baixa',
                    'blood_pressure_alert' => 'Alerta de pressão arterial',
                    'heart_rate_high_alert' => 'Alerta de frequência cardíaca alta',
                    'heart_rate_low_alert' => 'Alerta de frequência cardíaca baixa',
                    'remove_watch_alarm' => 'Alerta de remoção do relógio',
                    'remove_watch_sms_alert' => 'SMS de remoção do relógio',
                ],
                'event' => [
                    // O alarme disparado, e não um dos interruptores acima. Sai em `events`
                    // a partir do `AP10` da Vivistar e dos `AL*` da 4P Touch.
                    'alarm' => 'Alarme do dispositivo',
                ],
            ],
            'settings_system' => [
                'setting' => [
                    'working_mode' => 'Modo de funcionamento',
                    'device_password' => 'Palavra-passe do dispositivo',
                    'language_timezone' => 'Idioma e fuso horário',
                    'do_not_disturb' => 'Não incomodar',
                    'sound_profile' => 'Perfil de som',
                ],
                'action' => [
                    'push_message' => 'Enviar mensagem para o relógio',
                    'make_call' => 'Efetuar chamada',
                    // Ação e não contacto: o relógio liga para o número mal recebe o
                    // comando, em escuta silenciosa. Não há forma de o gravar sem disparar
                    // a chamada.
                    'monitor_number' => 'Número de monitorização',
                    'reset_device' => 'Repor dispositivo',
                    'restart_device' => 'Reiniciar dispositivo',
                    'power_off' => 'Desligar dispositivo',
                    'find_device' => 'Encontrar dispositivo',
                ],
                'event' => [
                    'device_state' => 'Estado do dispositivo',
                ],
            ],
        ];
    }
}
