<?php

namespace Hub\Domain\Capability\Definition;

final class WatchCapabilityDefinitions
{
    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'sortOrder' => 5, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'activity', 'label' => 'Atividade (passos)', 'sortOrder' => 6, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'heart_rate', 'label' => 'Frequência cardíaca', 'sortOrder' => 10, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'blood_pressure', 'label' => 'Pressão arterial', 'sortOrder' => 20, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'blood_oxygen', 'label' => 'Oxigénio no sangue', 'sortOrder' => 30, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'temperature', 'label' => 'Temperatura corporal', 'sortOrder' => 40, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'breath_rate', 'label' => 'Frequência respiratória', 'sortOrder' => 50, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'location', 'label' => 'Localização', 'sortOrder' => 60, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'blood_sugar', 'label' => 'Glicemia', 'sortOrder' => 65, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'sleep', 'label' => 'Sono', 'sortOrder' => 70, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'ecg', 'label' => 'ECG', 'sortOrder' => 80, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'hrv', 'label' => 'VFC', 'sortOrder' => 90, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'ppg', 'label' => 'PPG', 'sortOrder' => 100, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'rr_interval', 'label' => 'Intervalo RR', 'sortOrder' => 110, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'firmware_version', 'label' => 'Versão do firmware', 'sortOrder' => 120, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'device_status', 'label' => 'Estado do dispositivo', 'sortOrder' => 130, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'device_state', 'label' => 'Estado do dispositivo', 'sortOrder' => 1, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'auto_vitals_interval', 'label' => 'Intervalo de sinais vitais automáticos', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'heart_rate_measurement_interval', 'label' => 'Intervalo de medição da frequência cardíaca', 'sortOrder' => 20, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_pressure_measurement_interval', 'label' => 'Intervalo de medição da pressão arterial', 'sortOrder' => 30, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_oxygen_measurement_interval', 'label' => 'Intervalo de medição do oxigénio no sangue', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'temperature_measurement_interval', 'label' => 'Intervalo de medição da temperatura', 'sortOrder' => 50, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'breath_rate_measurement_interval', 'label' => 'Intervalo de medição da frequência respiratória', 'sortOrder' => 60, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'ecg_measurement_interval', 'label' => 'Intervalo de medição do ECG', 'sortOrder' => 70, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'hrv_measurement_interval', 'label' => 'Intervalo de medição da VFC', 'sortOrder' => 80, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'ppg_measurement_interval', 'label' => 'Intervalo de medição da PPG', 'sortOrder' => 90, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'rr_interval_measurement_interval', 'label' => 'Intervalo de medição do RR', 'sortOrder' => 100, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'heart_rate_continuous', 'label' => 'Frequência cardíaca contínua', 'sortOrder' => 110, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_oxygen_continuous', 'label' => 'Oxigénio no sangue contínuo', 'sortOrder' => 120, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_pressure_trend', 'label' => 'Tendência da pressão arterial', 'sortOrder' => 130, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'temperature_continuous', 'label' => 'Temperatura contínua', 'sortOrder' => 140, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'step_goal', 'label' => 'Meta de passos', 'sortOrder' => 150, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'sleep_monitoring', 'label' => 'Monitorização do sono', 'sortOrder' => 160, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'blood_pressure_calibration', 'label' => 'Calibração da pressão arterial', 'sortOrder' => 170, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'phonebook', 'label' => 'Lista telefónica', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'push_message', 'label' => 'Enviar mensagem para o relógio', 'sortOrder' => 5, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'call_whitelist', 'label' => 'Lista branca', 'sortOrder' => 30, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'whitelist_enabled', 'label' => 'Lista branca ativa', 'sortOrder' => 35, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'monitor_number', 'label' => 'Número de monitorização', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'sos_contacts', 'label' => 'Contactos SOS', 'sortOrder' => 50, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            // O alarme que o relógio dispara, e não um dos interruptores que o configuram: o
            // `AP10` da Vivistar e os `AL*` da 4P Touch saem no canal `events` com
            // `type: "alarm"` e `sos`, `lowBattery`, `fall` e `wearingNotice`. Faltava aqui, e
            // por isso o catálogo declarava a `fall_detection` que liga a deteção sem declarar
            // o alarme que ela produz -- metade do par.
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'alarm', 'label' => 'Alarme do dispositivo', 'sortOrder' => 5, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'alarm_clock', 'label' => 'Alarmes', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'medication_reminders', 'label' => 'Lembretes de medicação', 'sortOrder' => 20, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'low_battery_alert', 'label' => 'Alerta de bateria fraca', 'sortOrder' => 30, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'fall_detection', 'label' => 'Deteção de queda', 'sortOrder' => 40, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'fall_sensitivity', 'label' => 'Sensibilidade de queda', 'sortOrder' => 50, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'sos_sms_alert', 'label' => 'Alerta SOS por SMS', 'sortOrder' => 60, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'blood_oxygen_alert', 'label' => 'Alerta de oxigénio no sangue', 'sortOrder' => 70, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'temperature_high_alert', 'label' => 'Alerta de temperatura alta', 'sortOrder' => 80, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'temperature_low_alert', 'label' => 'Alerta de temperatura baixa', 'sortOrder' => 90, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'blood_pressure_alert', 'label' => 'Alerta de pressão arterial', 'sortOrder' => 100, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'heart_rate_high_alert', 'label' => 'Alerta de frequência cardíaca alta', 'sortOrder' => 110, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'heart_rate_low_alert', 'label' => 'Alerta de frequência cardíaca baixa', 'sortOrder' => 120, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'remove_watch_alarm', 'label' => 'Alerta de remoção do relógio', 'sortOrder' => 130, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'remove_watch_sms_alert', 'label' => 'SMS de remoção do relógio', 'sortOrder' => 140, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'working_mode', 'label' => 'Modo de funcionamento', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'device_password', 'label' => 'Palavra-passe do dispositivo', 'sortOrder' => 50, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'language_timezone', 'label' => 'Idioma e fuso horário', 'sortOrder' => 70, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'do_not_disturb', 'label' => 'Não incomodar', 'sortOrder' => 80, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'location_reporting_interval', 'label' => 'Intervalo de envio da localização', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'sound_profile', 'label' => 'Perfil de som', 'sortOrder' => 11, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'make_call', 'label' => 'Efetuar chamada', 'sortOrder' => 12, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'reset_device', 'label' => 'Repor dispositivo', 'sortOrder' => 14, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'restart_device', 'label' => 'Reiniciar dispositivo', 'sortOrder' => 15, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'power_off', 'label' => 'Desligar dispositivo', 'sortOrder' => 16, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'find_device', 'label' => 'Encontrar dispositivo', 'sortOrder' => 18, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'contacts', 'key' => 'center_number', 'label' => 'Número central', 'sortOrder' => 45, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'step_reporting_interval', 'label' => 'Intervalo de envio dos passos', 'sortOrder' => 180, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'pedometer_schedule', 'label' => 'Horário do pedómetro', 'sortOrder' => 190, 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
        ];
    }
}
