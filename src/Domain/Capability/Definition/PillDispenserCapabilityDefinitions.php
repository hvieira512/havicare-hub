<?php

namespace Hub\Domain\Capability\Definition;

/**
 * As capacidades que o dispensador M228 produz pelo protocolo TCP. Só telemetria e eventos:
 * o plano de medicação e os comandos, que precisam de downlink, entram numa camada posterior.
 */
final class PillDispenserCapabilityDefinitions
{
    public static function all(): array
    {
        return [
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'medication_level', 'label' => 'Nível de medicação', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'cells_remaining', 'label' => 'Células restantes', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'temperature', 'label' => 'Temperatura', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'humidity', 'label' => 'Humidade', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // O sinal WiFi/GSM viaja aqui, à maneira dos relógios, e não numa capacidade própria.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'device_status', 'label' => 'Estado do dispositivo', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'medication_intake', 'label' => 'Toma de medicação', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'device_fault', 'label' => 'Avaria', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            // A mesma chave do NCS e da pulseira: o botão de emergência é uma chamada de ajuda.
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'help_call', 'label' => 'Chamada de ajuda', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],

            // O que se configura. O plano reaproveita a chave que os relógios já usam.
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'medication_reminders', 'label' => 'Plano de medicação', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'dispense_mode', 'label' => 'Modo de dispensa', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'sound_profile', 'label' => 'Som', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'do_not_disturb', 'label' => 'Não incomodar', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'language_timezone', 'label' => 'Idioma e fuso horário', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],

            // O que se pede. Uma acção pede-se, não se configura.
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'dispense_now', 'label' => 'Dispensar agora', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'mute_alarm', 'label' => 'Silenciar', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'calibrate_clock', 'label' => 'Calibrar relógio', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'reset_tray', 'label' => 'Repor o prato', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'restart_device', 'label' => 'Reiniciar', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'reset_device', 'label' => 'Reposição de fábrica', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
        ];
    }
}
