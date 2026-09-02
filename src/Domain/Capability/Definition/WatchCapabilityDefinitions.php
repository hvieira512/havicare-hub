<?php

namespace Hub\Domain\Capability\Definition;

final class WatchCapabilityDefinitions
{
    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'activity', 'label' => 'Atividade (passos)', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'heart_rate', 'label' => 'Frequência cardíaca', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'blood_pressure', 'label' => 'Pressão arterial', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'blood_oxygen', 'label' => 'Oxigénio no sangue', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'temperature', 'label' => 'Temperatura corporal', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'breath_rate', 'label' => 'Frequência respiratória', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'location', 'label' => 'Localização', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'blood_sugar', 'label' => 'Glicemia', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'sleep', 'label' => 'Sono', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'ecg', 'label' => 'ECG', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'hrv', 'label' => 'VFC', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'ppg', 'label' => 'PPG', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'rr_interval', 'label' => 'Intervalo RR', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'firmware_version', 'label' => 'Versão do firmware', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'device_status', 'label' => 'Estado do dispositivo', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'device_state', 'label' => 'Estado do dispositivo', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'auto_vitals_interval', 'label' => 'Intervalo de sinais vitais automáticos', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'heart_rate_measurement_interval', 'label' => 'Intervalo de medição da frequência cardíaca', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_pressure_measurement_interval', 'label' => 'Intervalo de medição da pressão arterial', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_oxygen_measurement_interval', 'label' => 'Intervalo de medição do oxigénio no sangue', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'temperature_measurement_interval', 'label' => 'Intervalo de medição da temperatura', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'breath_rate_measurement_interval', 'label' => 'Intervalo de medição da frequência respiratória', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'ecg_measurement_interval', 'label' => 'Intervalo de medição do ECG', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'hrv_measurement_interval', 'label' => 'Intervalo de medição da VFC', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'ppg_measurement_interval', 'label' => 'Intervalo de medição da PPG', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'rr_interval_measurement_interval', 'label' => 'Intervalo de medição do RR', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'heart_rate_continuous', 'label' => 'Frequência cardíaca contínua', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_oxygen_continuous', 'label' => 'Oxigénio no sangue contínuo', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_pressure_trend', 'label' => 'Tendência da pressão arterial', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'temperature_continuous', 'label' => 'Temperatura contínua', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'step_goal', 'label' => 'Meta de passos', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'sleep_monitoring', 'label' => 'Monitorização do sono', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_pressure_calibration', 'label' => 'Calibração da pressão arterial', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'phonebook', 'label' => 'Lista telefónica', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'push_message', 'label' => 'Enviar mensagem para o relógio', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'call_whitelist', 'label' => 'Lista branca', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'whitelist_enabled', 'label' => 'Lista branca ativa', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'monitor_number', 'label' => 'Número de monitorização', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'sos_contacts', 'label' => 'Contactos SOS', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            // O alarme disparado, e não um dos interruptores que o configuram. Sai em
            // `events` a partir do `AP10` da Vivistar e dos `AL*` da 4P Touch.
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'alarm', 'label' => 'Alarme do dispositivo', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'alarm_clock', 'label' => 'Alarmes', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'medication_reminders', 'label' => 'Lembretes de medicação', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'low_battery_alert', 'label' => 'Alerta de bateria fraca', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'fall_detection', 'label' => 'Deteção de queda', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'fall_sensitivity', 'label' => 'Sensibilidade de queda', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'sos_sms_alert', 'label' => 'Alerta SOS por SMS', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'blood_oxygen_alert', 'label' => 'Alerta de oxigénio no sangue', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'temperature_high_alert', 'label' => 'Alerta de temperatura alta', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'temperature_low_alert', 'label' => 'Alerta de temperatura baixa', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'blood_pressure_alert', 'label' => 'Alerta de pressão arterial', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'heart_rate_high_alert', 'label' => 'Alerta de frequência cardíaca alta', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'heart_rate_low_alert', 'label' => 'Alerta de frequência cardíaca baixa', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'remove_watch_alarm', 'label' => 'Alerta de remoção do relógio', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'remove_watch_sms_alert', 'label' => 'SMS de remoção do relógio', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'working_mode', 'label' => 'Modo de funcionamento', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'device_password', 'label' => 'Palavra-passe do dispositivo', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'language_timezone', 'label' => 'Idioma e fuso horário', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'do_not_disturb', 'label' => 'Não incomodar', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'location_reporting_interval', 'label' => 'Intervalo de envio da localização', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'sound_profile', 'label' => 'Perfil de som', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'make_call', 'label' => 'Efetuar chamada', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'reset_device', 'label' => 'Repor dispositivo', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'restart_device', 'label' => 'Reiniciar dispositivo', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'power_off', 'label' => 'Desligar dispositivo', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'find_device', 'label' => 'Encontrar dispositivo', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'center_number', 'label' => 'Número central', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'step_reporting_interval', 'label' => 'Intervalo de envio dos passos', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'pedometer_schedule', 'label' => 'Horário do pedómetro', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
        ];
    }
}
